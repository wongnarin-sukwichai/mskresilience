<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Models\ImportBatch;
use App\Models\User;
use App\Models\WelfareRecord;
use App\Support\Settings;
use Database\Seeders\CommunitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Approval mode (TOR 4.1.1(6)-1, owner 2026-11-23: a switch in ตั้งค่าทั่วไป). While it is on, staff imports and web
 * edits wait as 'pending' with nothing saved; an admin approves (data checked again and saved) or rejects with a
 * reason. Admins' own changes never wait. Off (the default) = everything is live at once, as before.
 */
class ImportApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CommunitySeeder::class);
        Settings::set('import_requires_approval', true);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Welfare]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function importFile(User $user, int $elderly = 100): ImportBatch
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "ASSESSMENT_DATE,COMMUNITY_ID,V_ELDERLY_COUNT,V_CHILD_COUNT,V_DISABLED_COUNT,V_LOW_INCOME_COUNT\n20/01/2025,R001,{$elderly},1,1,1\n");
        $this->actingAs($user)->post('/data-import/welfare/validate', ['file' => new UploadedFile($path, 'w.csv', 'text/csv', null, true)]);
        $token = null;
        $this->actingAs($user)->get('/data-import/welfare')->assertInertia(function ($page) use (&$token) {
            $token = $page->toArray()['props']['preview']['token'];
        });
        $this->actingAs($user)->post('/data-import/welfare/confirm', ['token' => $token]);

        return ImportBatch::latest('id')->first();
    }

    public function test_a_staff_import_waits_and_the_admin_approval_saves_it()
    {
        $staff = $this->staff();
        $batch = $this->importFile($staff);
        $this->assertSame('pending', $batch->status);
        $this->assertSame(0, WelfareRecord::count());

        $admin = $this->admin();
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('pendingApprovals', 1));
        $this->actingAs($admin)->get('/import-approvals')->assertInertia(fn ($page) => $page
            ->component('admin/ImportApprovals')->has('batches.data', 1)->where('batches.data.0.status', 'pending'));
        $this->actingAs($staff)->get('/import-approvals')->assertForbidden();

        $this->actingAs($admin)->post("/import-approvals/{$batch->id}/approve")->assertSessionHas('status');
        $batch->refresh();
        $this->assertSame(['active', $admin->id], [$batch->status, $batch->reviewed_by]);
        $this->assertSame(100, WelfareRecord::sole()->v_elderly_count);
        $this->assertSame(['submitted', 'approved'], Activity::where('log_name', 'imports')->orderBy('id')->pluck('event')->all());
    }

    public function test_reject_needs_a_reason_and_saves_nothing()
    {
        $batch = $this->importFile($this->staff());
        $admin = $this->admin();

        $this->actingAs($admin)->post("/import-approvals/{$batch->id}/reject", ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAs($admin)->post("/import-approvals/{$batch->id}/reject", ['note' => 'ตัวเลขผิด'])->assertSessionHas('status');
        $batch->refresh();
        $this->assertSame(['rejected', 'ตัวเลขผิด'], [$batch->status, $batch->review_note]);
        $this->assertSame(0, WelfareRecord::count());

        // Neither approve nor cancel applies to a rejected batch.
        $this->actingAs($admin)->post("/import-approvals/{$batch->id}/approve")->assertSessionHas('error');
        $this->actingAs($admin)->delete("/import-history/{$batch->id}")->assertSessionHas('error');
    }

    public function test_staff_can_withdraw_a_pending_request_at_any_time()
    {
        $staff = $this->staff();
        $batch = $this->importFile($staff);
        $this->travel(3)->days();

        $this->actingAs($staff)->delete("/import-history/{$batch->id}")->assertSessionHas('status');
        $this->assertTrue($batch->refresh()->isCancelled());
        $this->actingAs($this->admin())->get('/import-approvals')->assertInertia(fn ($page) => $page->has('batches.data', 0));
    }

    public function test_approval_checks_the_data_again()
    {
        $staff = $this->staff();
        // Two pending web adds of the same row: the first approval saves it, the second must now fail (row exists).
        $values = ['ASSESSMENT_DATE' => '2025-01-20', 'COMMUNITY_ID' => 'R001', 'V_ELDERLY_COUNT' => '7'];
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $values])->assertSessionHas('status');
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $values])->assertSessionHas('status');
        [$first, $second] = ImportBatch::orderBy('id')->get();
        $this->assertSame(['pending', 'pending'], [$first->status, $second->status]);
        $this->assertSame(0, WelfareRecord::count());

        $admin = $this->admin();
        $this->actingAs($admin)->post("/import-approvals/{$first->id}/approve")->assertSessionHas('status');
        $this->actingAs($admin)->post("/import-approvals/{$second->id}/approve")->assertSessionHas('error');
        $this->assertSame('pending', $second->refresh()->status);
        $this->assertSame(7, WelfareRecord::sole()->v_elderly_count);
    }

    public function test_admins_and_mode_off_go_live_at_once()
    {
        $this->assertSame('active', $this->importFile($this->admin())->status);

        Settings::set('import_requires_approval', false);
        $this->assertSame('active', $this->importFile($this->staff(), 200)->status);
        $this->assertSame(200, WelfareRecord::sole()->v_elderly_count);
    }
}
