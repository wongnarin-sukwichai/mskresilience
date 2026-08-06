<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_admin_and_staff_are_redirected_to_the_dashboard_after_login()
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_members_are_redirected_to_the_homepage_after_login()
    {
        $user = User::factory()->create(['role' => UserRole::Member]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('home', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    /**
     * Regression test: the `guest` middleware (on /login) redirects an already-authenticated
     * visitor away before their new login attempt is ever processed. Left at its Laravel default,
     * that redirect always targets route('dashboard') if it exists — with no regard for role —
     * which sent an already-logged-in member straight into a 403 instead of back to a usable page.
     * See AppServiceProvider::boot() for the fix (RedirectIfAuthenticated::redirectUsing()).
     */
    public function test_already_authenticated_member_visiting_login_is_sent_home_not_to_the_403ing_dashboard()
    {
        $user = User::factory()->create(['role' => UserRole::Member]);

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect(route('home', absolute: false));
    }

    public function test_already_authenticated_admin_visiting_login_is_sent_to_the_dashboard()
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($user)->get('/login');

        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
