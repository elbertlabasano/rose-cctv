<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\KeycloakAuthenticationService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KeycloakAuthenticationTest extends TestCase
{
    private array $httpHistory = [];

    private MockHandler $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
        $this->http = new MockHandler;
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::history($this->httpHistory));
        config([
            'sso.enabled' => true,
            'sso.idcode_claim' => 'idcode',
            'services.keycloak' => [
                'base_url' => 'https://sso.rosebakeshop.net', 'realms' => 'jathnier',
                'client_id' => 'watch', 'client_secret' => 'test-server-secret',
                'redirect' => 'http://127.0.0.1:8002/auth/keycloak/callback',
                'guzzle' => ['handler' => $stack, 'allow_redirects' => false],
            ],
        ]);
    }

    private function begin(): array
    {
        $response = $this->get('/auth/keycloak/redirect')->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    private function answer(array $claims): void
    {
        $this->http->append(
            new Response(200, [], json_encode(['access_token' => 'test-access-token', 'refresh_token' => 'test-refresh-token', 'token_type' => 'Bearer'])),
            new Response(200, [], json_encode($claims)),
        );
    }

    private function completeLogin(array $query, array $override = [])
    {
        return $this->get('/auth/keycloak/callback?'.http_build_query(array_merge([
            'state' => $query['state'], 'code' => 'one-use-code',
        ], $override)));
    }

    private function assertFailed($response): void
    {
        $response->assertRedirect('/login')->assertSessionHasErrors(['sso' => KeycloakAuthenticationService::LOGIN_ERROR]);
        $response->assertSessionMissing('state')->assertSessionMissing('code_verifier')->assertSessionMissing('rose_sso_started_at');
        $this->assertGuest();
    }

    public function test_login_exposes_only_enabled_flag_and_redirect_has_state_and_s256_pkce(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('sso_enabled', true)->missing('services')->missing('client_secret'));
        $query = $this->begin();
        $this->assertSame('watch', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('http://127.0.0.1:8002/auth/keycloak/callback', $query['redirect_uri']);
        $this->assertSame($query['state'], session('state'));
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', session('code_verifier'), true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertArrayNotHasKey('client_secret', $query);
        $this->assertArrayNotHasKey('prompt', $query);
        $this->assertCount(0, $this->httpHistory);
    }

    public function test_success_links_exact_existing_account_and_preserves_password_roles_and_branches(): void
    {
        $user = User::factory()->create(['idcode' => '001234', 'role' => 'technician', 'all_branches' => false, 'team' => 'Support']);
        $branch = Branch::create(['code' => 'SSO', 'name' => 'Bakery']);
        $user->branches()->attach($branch);
        $password = $user->password;
        $query = $this->begin();
        $oldSession = session()->getId();
        $verifier = session('code_verifier');
        $this->answer(['sub' => 'employee-subject', 'idcode' => '001234', 'email' => 'different@rosebakeshop.net', 'roles' => ['admin']]);
        $this->withSession(['url.intended' => 'https://untrusted.example'])->completeLogin($query)
            ->assertRedirect('/dashboard')->assertSessionMissing('state')->assertSessionMissing('code_verifier')->assertSessionMissing('rose_sso_started_at');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame('technician', $user->fresh()->role);
        $this->assertSame('Support', $user->fresh()->team);
        $this->assertFalse($user->fresh()->all_branches);
        $this->assertSame([$branch->id], $user->fresh()->branches->modelKeys());
        $this->assertSame('employee-subject', $user->fresh()->keycloak_subject);
        $this->assertSame('https://sso.rosebakeshop.net/realms/jathnier', $user->fresh()->keycloak_issuer);
        $this->assertDatabaseCount('users', 1);
        $this->assertArrayNotHasKey('keycloak_subject', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('keycloak_issuer', $user->fresh()->toArray());
        $this->assertStringNotContainsString('test-access-token', json_encode(session()->all()));
        $this->assertStringNotContainsString('test-refresh-token', json_encode(session()->all()));
        $this->assertCount(2, $this->httpHistory);
        $tokenRequest = $this->httpHistory[0]['request'];
        $this->assertSame('https://sso.rosebakeshop.net/realms/jathnier/protocol/openid-connect/token', (string) $tokenRequest->getUri());
        parse_str((string) $tokenRequest->getBody(), $fields);
        $this->assertSame($verifier, $fields['code_verifier']);
        $this->assertSame('test-server-secret', $fields['client_secret']);
        $this->assertSame('Bearer test-access-token', $this->httpHistory[1]['request']->getHeaderLine('Authorization'));
    }

    public function test_bad_state_expired_flow_missing_verifier_and_replay_never_exchange_a_code(): void
    {
        $query = $this->begin();
        $this->assertFailed($this->completeLogin($query, ['state' => 'attacker-state']));
        $this->assertFailed($this->completeLogin($query));
        $query = $this->begin();
        $this->withSession(['rose_sso_started_at' => now()->subMinutes(11)->timestamp]);
        $this->assertFailed($this->completeLogin($query));
        $query = $this->begin();
        session()->forget('code_verifier');
        $this->assertFailed($this->completeLogin($query));
        $this->assertCount(0, $this->httpHistory);
    }

    public function test_unknown_or_malformed_codes_do_not_match_email_or_create_users(): void
    {
        $user = User::factory()->create(['idcode' => '001234']);
        foreach ([null, ['001234'], ' 001234', '001234 ', '1234', '', 1234, 'unknown'] as $idcode) {
            $query = $this->begin();
            $this->answer(['sub' => 'new-subject', 'idcode' => $idcode, 'email' => $user->email]);
            $this->assertFailed($this->completeLogin($query));
            $this->assertNull($user->fresh()->keycloak_subject);
            $this->assertDatabaseCount('users', 1);
        }
    }

    public function test_inactive_accounts_and_existing_different_identity_are_rejected(): void
    {
        $user = User::factory()->create(['idcode' => 'EMP-10', 'active' => false]);
        $query = $this->begin();
        $this->answer(['sub' => 'new-subject', 'idcode' => 'EMP-10']);
        $this->assertFailed($this->completeLogin($query));
        $user->forceFill(['active' => true, 'keycloak_issuer' => 'https://sso.rosebakeshop.net/realms/jathnier', 'keycloak_subject' => 'original-subject'])->save();
        $query = $this->begin();
        $this->answer(['sub' => 'new-subject', 'idcode' => 'EMP-10']);
        $this->assertFailed($this->completeLogin($query));
        $this->assertSame('original-subject', $user->fresh()->keycloak_subject);
    }

    public function test_linked_account_requires_same_subject_and_code_and_cannot_move_to_another_user(): void
    {
        $user = User::factory()->create(['idcode' => '001234']);
        $user->forceFill(['keycloak_issuer' => 'https://sso.rosebakeshop.net/realms/jathnier', 'keycloak_subject' => 'stable-subject'])->save();
        User::factory()->create(['idcode' => 'changed-code']);
        $query = $this->begin();
        $this->answer(['sub' => 'stable-subject', 'idcode' => 'changed-code']);
        $this->assertFailed($this->completeLogin($query));
        $query = $this->begin();
        $this->answer(['sub' => 'stable-subject', 'idcode' => '001234']);
        $this->completeLogin($query)->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_provider_failure_logs_only_exception_class_and_status_and_displays_a_safe_error(): void
    {
        $query = $this->begin();
        $this->http->append(new Response(400, [], '{"error":"test-server-secret test-access-token"}'));
        Log::shouldReceive('warning')->once()->with('Rose SSO sign-in failed.', ['exception_class' => ClientException::class, 'http_status' => 400]);
        $this->assertFailed($this->completeLogin($query));
    }

    public function test_connection_failure_logs_only_safe_errno_and_explains_server_connectivity(): void
    {
        $query = $this->begin();
        $this->http->append(new ConnectException('Never log test-server-secret or tokens',
            new Request('POST', 'https://sso.rosebakeshop.net/realms/jathnier/protocol/openid-connect/token'),
            null, ['errno' => 7, 'error' => 'Never log raw context']));
        Log::shouldReceive('warning')->once()->with('Rose SSO sign-in failed.', [
            'exception_class' => ConnectException::class, 'curl_errno' => 7,
        ]);
        $this->completeLogin($query)->assertRedirect('/login')
            ->assertSessionHasErrors(['sso' => 'RoseWatch could not connect to Rose sign-in. Please try again shortly.'])
            ->assertSessionMissing('state')->assertSessionMissing('code_verifier');
        $this->assertGuest();
    }

    public function test_disabled_sso_and_cancelled_login_do_not_call_provider(): void
    {
        config(['sso.enabled' => false]);
        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('sso_enabled', false));
        $this->assertFailed($this->get('/auth/keycloak/redirect'));
        config(['sso.enabled' => true]);
        $query = $this->begin();
        $this->assertFailed($this->completeLogin($query, ['error' => 'access_denied']));
        $this->assertCount(0, $this->httpHistory);
    }

    public function test_password_login_still_works_and_logout_is_local(): void
    {
        $user = User::factory()->create(['password' => 'password', 'idcode' => '20260211']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => false])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertCount(0, $this->httpHistory);
    }

    public function test_admin_can_save_codes_with_leading_zeros_but_not_duplicates_or_identity_bindings(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'all_branches' => true]);
        $user = User::factory()->create(['idcode' => null]);
        $data = ['name' => $user->name, 'email' => $user->email, 'idcode' => '001234', 'role' => 'viewer',
            'active' => true, 'all_branches' => true, 'branch_ids' => [], 'reason' => 'Link Rose account',
            'keycloak_subject' => 'injected-subject', 'keycloak_issuer' => 'injected-issuer'];
        $this->actingAs($admin)->put('/users/'.$user->id, $data)->assertSessionHasNoErrors();
        $this->assertSame('001234', $user->fresh()->idcode);
        $this->assertNull($user->fresh()->keycloak_subject);
        $this->put('/users/'.$admin->id, [...$data, 'name' => $admin->name, 'email' => $admin->email, 'role' => 'admin'])
            ->assertSessionHasErrors('idcode');
        $this->put('/users/'.$user->id, [...$data, 'idcode' => 'invalid code'])->assertSessionHasErrors('idcode');
    }
}
