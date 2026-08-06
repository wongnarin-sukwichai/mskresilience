<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_admin_and_staff_can_visit_the_dashboard()
    {
        foreach ([UserRole::Admin, UserRole::Staff] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);

            $response = $this->get('/dashboard');
            $response->assertStatus(200);
        }
    }

    public function test_members_cannot_visit_the_dashboard()
    {
        $user = User::factory()->create(['role' => UserRole::Member]);
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(403);
    }
}
