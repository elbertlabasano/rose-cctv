<?php

namespace Tests\Feature;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSafeTestDatabase($this->app);
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertExitCode(0);
        $this->withoutVite();
    }

    public function test_guests_can_open_the_homepage_without_workspace_data(): void
    {
        $response = $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')->where('can_open_workspace', false)->where('auth.user', null)
            ->where('year', now('Asia/Manila')->year)->missing('workspace')->missing('csrf_token'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/cameras')->assertRedirect('/login');
    }

    public function test_signed_in_users_get_a_workspace_entry_without_private_account_fields(): void
    {
        $user = User::factory()->create(['role' => 'viewer', 'remember_token' => 'PRIVATE-REMEMBER-TOKEN']);
        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')->where('can_open_workspace', true)->where('auth.user.id', $user->id)
            ->missing('auth.user.password')->missing('auth.user.remember_token')->missing('workspace'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_accounts_are_not_offered_workspace_access(): void
    {
        $user = User::factory()->create(['active' => false]);
        $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Home')->where('can_open_workspace', false)->missing('workspace'));
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }
}
