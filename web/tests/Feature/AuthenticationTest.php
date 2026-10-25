<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_login_page_is_available_and_workspace_requires_authentication(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_valid_credentials_authenticate_and_email_is_normalized(): void
    {
        $user = User::factory()->create(['email' => 'alex@example.com']);
        $this->post('/login', ['email' => ' ALEX@EXAMPLE.COM ', 'password' => 'password'])
            ->assertRedirect('/dashboard')->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Workspace')
            ->where('auth.user.email', $user->email)->missing('auth.user.password')->missing('auth.user.remember_token'));
        $this->get('/login')->assertRedirect('/dashboard');
    }

    public function test_wrong_password_and_unknown_email_share_the_same_message(): void
    {
        User::factory()->create(['email' => 'known@example.com']);
        foreach (['known@example.com', 'unknown@example.com'] as $email) {
            $this->from('/login')->post('/login', ['email' => $email, 'password' => 'incorrect'])
                ->assertRedirect('/login')->assertSessionHasErrors([
                    'email' => 'The email or password is incorrect. Please try again.',
                ]);
            $this->assertGuest();
        }
    }

    public function test_inputs_are_validated_before_authentication(): void
    {
        $this->post('/login', ['email' => 'invalid', 'password' => '', 'remember' => 'invalid'])
            ->assertSessionHasErrors(['email', 'password', 'remember']);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $this->freezeTime();
        $email = 'throttled@example.com';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'Too many sign-in attempts. Try again in 60 seconds.']);
        $this->assertGuest();
    }

    public function test_successful_login_clears_failed_attempts_and_remember_sets_cookie(): void
    {
        $user = User::factory()->create(['email' => 'remember@example.com']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => true])
            ->assertRedirect('/dashboard')->assertCookie(auth()->guard()->getRecallerName());
        $this->assertSame(0, RateLimiter::attempts(hash('sha256', $user->email.'|127.0.0.1')));
    }

    public function test_logout_ends_authentication_and_rotates_csrf_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['_token' => 'old-token'])->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNotSame('old-token', session()->token());
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'Unexpected', 'email' => 'new@example.com', 'password' => 'password'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }
}
