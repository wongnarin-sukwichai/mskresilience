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

    public function test_admin_staff_and_member_can_visit_the_dashboard()
    {
        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);

            $response = $this->get('/dashboard');
            $response->assertStatus(200);
        }
    }

    public function test_admin_and_staff_can_visit_data_import_but_member_cannot()
    {
        foreach ([UserRole::Admin, UserRole::Staff] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);

            $this->get('/data-import')->assertStatus(200);
        }

        $member = User::factory()->create(['role' => UserRole::Member]);
        $this->actingAs($member);

        $this->get('/data-import')->assertStatus(403);
    }

    public function test_only_admin_can_visit_communities()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $this->get('/communities')->assertStatus(200);

        foreach ([UserRole::Staff, UserRole::Member] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);

            $this->get('/communities')->assertStatus(403);
        }
    }

    public function test_admin_and_staff_can_visit_hazard_analysis_and_events_ld_but_member_cannot()
    {
        foreach ([UserRole::Admin, UserRole::Staff] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);

            $this->get('/hazard-analysis')->assertStatus(200);
            $this->get('/events-ld')->assertStatus(200);
        }

        $member = User::factory()->create(['role' => UserRole::Member]);
        $this->actingAs($member);

        $this->get('/hazard-analysis')->assertStatus(403);
        $this->get('/events-ld')->assertStatus(403);
    }

    public function test_only_admin_can_visit_scoring_criteria()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        $this->get('/scoring-criteria')->assertStatus(200);

        foreach ([UserRole::Staff, UserRole::Member] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);

            $this->get('/scoring-criteria')->assertStatus(403);
        }
    }
}
