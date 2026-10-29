<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_all_eight_work_groups()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/data-import')->assertInertia(fn ($page) => $page
            ->component('admin/DataImport')
            ->has('groups', 8));
    }

    public function test_staff_sees_only_granted_work_groups()
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Health, WorkGroup::Kpi]]);

        $this->actingAs($staff)->get('/data-import')->assertInertia(fn ($page) => $page
            ->has('groups', 2)
            ->where('groups.0.value', 'health')
            ->where('groups.1.value', 'kpi'));
    }

    public function test_staff_without_grants_sees_no_groups()
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => []]);

        $this->actingAs($staff)->get('/data-import')->assertInertia(fn ($page) => $page->has('groups', 0));
    }

    public function test_member_cannot_reach_the_import_module()
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($member)->get('/data-import')->assertStatus(403);
        $this->actingAs($member)->get('/data-import/health')->assertStatus(403);
    }

    public function test_staff_can_open_a_granted_group()
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Disaster]]);

        $this->actingAs($staff)->get('/data-import/disaster')->assertInertia(fn ($page) => $page
            ->component('admin/DataImportUpload')
            ->where('group.label', 'งานป้องกันภัย')
            ->where('group.sheet', 'งาน ปภ1'));
    }

    public function test_staff_is_turned_away_from_an_ungranted_group()
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Health]]);

        $this->actingAs($staff)->get('/data-import/kpi')
            ->assertRedirect('/data-import')
            ->assertSessionHas('error', 'คุณไม่มีสิทธิ์นำเข้าข้อมูลงาน KPI');
    }

    public function test_unknown_group_is_not_found()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/data-import/not-a-group')->assertStatus(404);
    }
}
