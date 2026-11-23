<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Scoring\CriteriaSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * บันทึกการแก้ไข (TOR คุณลักษณะ 2.1-6: "บันทึกว่าใครแก้ไขข้อมูล เมื่อใด", checked by editing data).
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> the rows the page gets */
    private function rows(User $admin, string $query = ''): array
    {
        $rows = null;
        $this->actingAs($admin)->get('/audit-log'.$query)->assertInertia(function ($page) use (&$rows) {
            $rows = $page->toArray()['props']['activities']['data'];
        });

        return $rows;
    }

    public function test_only_admin_opens_the_audit_log()
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))->get('/audit-log')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))->get('/audit-log')->assertForbidden();
    }

    public function test_editing_a_user_records_who_when_and_old_and_new_values_but_never_the_password()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'ผู้ดูแล']);
        $target = User::factory()->create(['role' => UserRole::Member, 'name' => 'ชื่อเดิม']);

        $this->actingAs($admin)->put("/users/{$target->id}", [
            'name' => 'ชื่อใหม่',
            'email' => $target->email,
            'password' => 'secret-pass-123',
            'role' => 'staff',
            'work_groups' => ['welfare'],
        ])->assertSessionHasNoErrors();

        $rows = $this->rows($admin, '?log=users');
        $update = collect($rows)->firstWhere('event', 'updated');
        $this->assertSame('ผู้ดูแล', $update['user']);
        $this->assertNotNull($update['at']);
        $changes = collect($update['changes'])->keyBy('field');
        $this->assertSame('ชื่อเดิม', $changes['ชื่อ']['old']);
        $this->assertSame('ชื่อใหม่', $changes['ชื่อ']['new']);
        $this->assertSame('ผู้บริหาร', $changes['สิทธิ์การใช้งาน']['old']);
        $this->assertSame('เจ้าหน้าที่', $changes['สิทธิ์การใช้งาน']['new']);

        $this->assertNotNull(collect($rows)->firstWhere('event', 'password'));
        $this->assertStringNotContainsString('secret-pass-123', Activity::all()->toJson());
        $this->assertStringNotContainsString('"password"', Activity::pluck('properties')->toJson());
    }

    public function test_community_and_criteria_changes_are_logged_with_only_the_changed_weights()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        $community = Community::create(['code' => 'R001', 'name' => 'ตักศิลา 1']);
        $community->update(['name' => 'ตักศิลา 2']);
        $community->delete();

        $weights = CriteriaSchema::defaults();
        $set = ScoringCriteriaSet::create(['name' => 'ชุดทดสอบ', 'start_fiscal_year' => 2568, 'is_locked' => false, 'weights' => $weights]);
        $section = array_key_first($weights);
        $row = array_key_first($weights[$section]);
        $column = array_key_first($weights[$section][$row]);
        $changed = $weights;
        $changed[$section][$row][$column] = 0.1234;
        $set->update(['weights' => $changed]);

        $rows = collect($this->rows($admin));
        $this->assertSame(['created', 'updated', 'deleted'], $rows->where('log', 'communities')->pluck('event')->reverse()->values()->all());
        $this->assertSame('ลบชุมชน: R001 ตักศิลา 2', $rows->where('log', 'communities')->firstWhere('event', 'deleted')['description']);

        $weightChange = $rows->where('log', 'criteria')->firstWhere('event', 'updated');
        $this->assertCount(1, $weightChange['changes']);
        $this->assertSame("ค่าน้ำหนัก {$section}.{$row}.{$column}", $weightChange['changes'][0]['field']);
        $this->assertSame('0.1234', $weightChange['changes'][0]['new']);
    }

    public function test_filters_by_user_and_type()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $other = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);
        Community::create(['code' => 'R001', 'name' => 'หนึ่ง']);
        $this->actingAs($other);
        Community::create(['code' => 'R002', 'name' => 'สอง']);

        $this->assertCount(1, $this->rows($admin, "?log=communities&user={$admin->id}"));
        $this->assertCount(2, $this->rows($admin, '?log=communities'));
        $this->assertCount(0, $this->rows($admin, '?log=criteria'));
    }
}
