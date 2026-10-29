<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login()
    {
        $response = $this->get('/users');

        $response->assertRedirect('/login');
    }

    public function test_staff_cannot_manage_users()
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        $response = $this->actingAs($staff)->get('/users');

        $response->assertStatus(403);
    }

    public function test_members_cannot_manage_users()
    {
        $member = User::factory()->create(['role' => UserRole::Member]);

        $response = $this->actingAs($member)->get('/users');

        $response->assertStatus(403);
    }

    public function test_admin_can_view_the_user_list()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->get('/users');

        $response->assertStatus(200);
    }

    public function test_admin_can_create_a_user()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->post('/users', [
            'name' => 'ทดสอบ ระบบ',
            'email' => 'newstaff@example.com',
            'password' => 'password123',
            'role' => 'staff',
            'work_groups' => ['health', 'welfare'],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', [
            'email' => 'newstaff@example.com',
            'role' => 'staff',
        ]);

        $created = User::where('email', 'newstaff@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('password123', $created->password));
        $this->assertTrue($created->canImport(WorkGroup::Health));
        $this->assertFalse($created->canImport(WorkGroup::Kpi));
    }

    public function test_creating_a_user_requires_a_unique_email()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $existing = User::factory()->create(['role' => UserRole::Member]);

        $response = $this->actingAs($admin)->post('/users', [
            'name' => 'Duplicate',
            'email' => $existing->email,
            'password' => 'password123',
            'role' => 'member',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_admin_can_update_a_user()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $target = User::factory()->create(['role' => UserRole::Member, 'name' => 'Old Name']);

        $response = $this->actingAs($admin)->put("/users/{$target->id}", [
            'name' => 'New Name',
            'email' => $target->email,
            'password' => '',
            'role' => 'staff',
            'work_groups' => ['kpi'],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'name' => 'New Name',
            'role' => 'staff',
        ]);
    }

    public function test_admin_can_delete_a_user()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $target = User::factory()->create(['role' => UserRole::Member]);

        $response = $this->actingAs($admin)->delete("/users/{$target->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_admin_cannot_delete_their_own_account()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->delete("/users/{$admin->id}");

        // Refused as a red toast (flash 'error'), not a 403 page.
        $response->assertSessionHas('error', 'ไม่สามารถลบบัญชีของตนเองได้');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_an_admin_can_delete_another_admin_when_a_third_admin_remains()
    {
        $admin1 = User::factory()->create(['role' => UserRole::Admin]);
        $admin2 = User::factory()->create(['role' => UserRole::Admin]);
        $admin3 = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin1)->delete("/users/{$admin2->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('users', ['id' => $admin2->id]);
        $this->assertDatabaseHas('users', ['id' => $admin3->id]);
    }

    /**
     * The only way to reach "delete the last admin" without also tripping the self-delete guard
     * (destroy requires role:admin, so the actor is always an admin themselves) is demotion via
     * update, not delete — this is the meaningful case that guardLastAdmin() actually protects.
     */
    public function test_the_sole_remaining_admin_cannot_demote_themselves()
    {
        $soleAdmin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($soleAdmin)->put("/users/{$soleAdmin->id}", [
            'name' => $soleAdmin->name,
            'email' => $soleAdmin->email,
            'password' => '',
            'role' => 'staff',
            'work_groups' => ['health'],
        ]);

        // Refused as a form error under the role field, not a 403 page.
        $response->assertSessionHasErrors('role');
        $this->assertDatabaseHas('users', ['id' => $soleAdmin->id, 'role' => 'admin']);
    }

    public function test_demoting_an_admin_is_allowed_when_another_admin_remains()
    {
        $admin1 = User::factory()->create(['role' => UserRole::Admin]);
        $admin2 = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin1)->put("/users/{$admin2->id}", [
            'name' => $admin2->name,
            'email' => $admin2->email,
            'password' => '',
            'role' => 'staff',
            'work_groups' => ['health'],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['id' => $admin2->id, 'role' => 'staff']);
    }

    public function test_staff_must_be_granted_at_least_one_work_group()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        foreach ([[], null] as $groups) {
            $this->actingAs($admin)->post('/users', [
                'name' => 'ไม่มีกลุ่ม',
                'email' => 'nogroup@example.com',
                'password' => 'password123',
                'role' => 'staff',
                'work_groups' => $groups,
            ])->assertSessionHasErrors(['work_groups' => 'เจ้าหน้าที่ต้องได้รับสิทธิ์นำเข้าข้อมูลอย่างน้อย 1 กลุ่มงาน']);
        }

        $this->assertDatabaseMissing('users', ['email' => 'nogroup@example.com']);
    }

    public function test_unknown_work_group_is_rejected()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->post('/users', [
            'name' => 'ผิดกลุ่ม',
            'email' => 'badgroup@example.com',
            'password' => 'password123',
            'role' => 'staff',
            'work_groups' => ['not_a_group'],
        ])->assertSessionHasErrors('work_groups.0');
    }

    public function test_admin_and_member_never_store_work_groups()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Health]]);

        // Changing a staff member to member clears their groups instead of leaving them stale.
        $this->actingAs($admin)->put("/users/{$staff->id}", [
            'name' => $staff->name,
            'email' => $staff->email,
            'password' => '',
            'role' => 'member',
            'work_groups' => ['health'],
        ])->assertSessionHasNoErrors();

        $staff->refresh();
        $this->assertCount(0, $staff->work_groups);
        $this->assertFalse($staff->canImport(WorkGroup::Health));

        // Admin imports every group implicitly, with nothing stored.
        $this->assertTrue($admin->canImport(WorkGroup::Kpi));
        $this->assertTrue($admin->canImport(WorkGroup::DisasterEvent));
    }

    public function test_validation_messages_are_in_thai()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $existing = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($admin)->post('/users', [
            'name' => 'ซ้ำ',
            'email' => $existing->email,
            'password' => 'short',
            'role' => 'member',
        ])->assertSessionHasErrors([
            'email' => 'อีเมลนี้มีอยู่ในระบบแล้ว',
            'password' => 'รหัสผ่าน ต้องยาวอย่างน้อย 8 ตัวอักษร',
        ]);
    }

    public function test_index_sends_the_eight_work_groups()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/users')->assertInertia(fn ($page) => $page
            ->has('workGroups', 8)
            ->where('workGroups.1.label', 'งานป้องกันภัย')
            ->where('workGroups.1.sheet', 'งาน ปภ1'));
    }
}
