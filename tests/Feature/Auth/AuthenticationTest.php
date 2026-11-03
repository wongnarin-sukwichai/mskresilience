<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_url_shows_the_home_page_with_the_login_modal()
    {
        $this->get('/login')->assertRedirect(route('home'));

        $this->get('/')->assertInertia(fn ($page) => $page->component('Welcome')->where('openLogin', true));
        $this->get('/')->assertInertia(fn ($page) => $page->where('openLogin', false));
    }

    public function test_guests_opening_an_admin_page_land_on_the_home_page()
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get('/login')->assertRedirect(route('home'));
    }

    public function test_login_redirect_keeps_the_status_message()
    {
        // Flashed the way NewPasswordController's to_route('login')->with('status') leaves it.
        $this->withSession(['status' => 'ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว กรุณาเข้าสู่ระบบ', '_flash' => ['old' => [], 'new' => ['status']]])->get('/login');

        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('openLogin', true)
            ->where('flash.status', 'ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว กรุณาเข้าสู่ระบบ'));
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
