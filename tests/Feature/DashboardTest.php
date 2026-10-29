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

    public function test_every_role_can_visit_the_six_display_pages()
    {
        $pages = [
            '/dashboard' => 'admin/About',
            '/risk-overview' => 'admin/RiskOverview',
            '/community-map' => 'admin/CommunityMap',
            '/risk-factors' => 'admin/RiskFactors',
            '/impact-recovery' => 'admin/ImpactRecovery',
            '/mcr2030-kpi' => 'admin/Mcr2030Kpi',
        ];

        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));

            foreach ($pages as $url => $component) {
                $this->get($url)->assertStatus(200)->assertInertia(fn ($page) => $page->component($component));
            }
        }
    }

    public function test_old_display_pages_are_gone()
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        foreach (['/community-data', '/disaster-overview', '/hazard-analysis', '/events-ld'] as $url) {
            $this->get($url)->assertStatus(404);
        }
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
