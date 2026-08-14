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

    public function test_admin_staff_and_member_are_redirected_to_the_dashboard_after_login()
    {
        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $response = $this->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

            $this->assertAuthenticated();
            $response->assertRedirect(route('dashboard', absolute: false));

            $this->post('/logout');
        }
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
     * that redirect always targets route('dashboard') if it exists — with no regard for role. Any
     * role not covered by User::canAccessAdminPanel() would get bounced into a 403 instead of a
     * usable page. See AppServiceProvider::boot() for the fix (RedirectIfAuthenticated::redirectUsing()).
     */
    public function test_already_authenticated_users_are_sent_to_the_dashboard_regardless_of_role()
    {
        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $response = $this->actingAs($user)->get('/login');

            $response->assertRedirect(route('dashboard', absolute: false));
        }
    }
}
