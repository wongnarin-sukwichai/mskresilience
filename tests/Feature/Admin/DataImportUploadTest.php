<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Import\ImportColumns;
use App\Import\ImportSheets;
use App\Models\Community;
use App\Models\ImportBatch;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Models\WelfareRecord;
use App\Scoring\CriteriaSchema;
use Database\Seeders\CommunitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DataImportUploadTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['ASSESSMENT_DATE', 'COMMUNITY_ID', 'COMMUNITY_NAME', 'V_ELDERLY_COUNT', 'V_CHILD_COUNT', 'V_DISABLED_COUNT', 'V_LOW_INCOME_COUNT'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CommunitySeeder::class);
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<string>|null  $headers
     */
    private function xlsx(array $rows, ?array $headers = null): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([$headers ?? self::HEADERS, ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'สวัสดิการ.xlsx', null, null, true);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Welfare]]);
    }

    private function upload(User $user, UploadedFile $file)
    {
        return $this->actingAs($user)->post('/data-import/welfare/validate', ['file' => $file]);
    }

    private function preview(User $user): array
    {
        $props = null;
        $this->actingAs($user)->get('/data-import/welfare')->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props['preview'];
    }

    public function test_welfare_sheet_rules_match_the_template_columns()
    {
        $this->assertSame(ImportColumns::for(WorkGroup::Welfare), array_keys(ImportSheets::for(WorkGroup::Welfare)->rules()));
    }

    public function test_valid_file_is_previewed_and_nothing_is_saved_yet()
    {
        $staff = $this->staff();
        $this->upload($staff, $this->xlsx([
            [Date::stringToExcel('2025-01-20'), 'R001', 'ศรีสวัสดิ์ 2', 186, 142, 31, 96],
            ['21/01/2025', 'r002', null, 132, '-', 22, 'n/a'],
        ]))->assertRedirect('/data-import/welfare');

        $preview = $this->preview($staff);
        $this->assertSame([], $preview['errors']);
        $this->assertSame(2, $preview['summary']['rowCount']);
        $this->assertSame(2, $preview['summary']['blankCount']);
        $this->assertSame(['R001', 'R002'], $preview['summary']['included']);
        $this->assertCount(30, $preview['summary']['missing']);
        $this->assertSame('2025-01-21', $preview['rows'][1]['values']['ASSESSMENT_DATE']);
        $this->assertNull($preview['rows'][1]['values']['V_CHILD_COUNT']);
        $this->assertArrayNotHasKey('saveRows', $preview);
        $this->assertSame(0, WelfareRecord::count());

        // The step-by-step checklist: read, headers, one line per column, duplicates, compare with DB.
        $this->assertFalse($preview['fatal']);
        $this->assertCount(2 + 7 + 2, $preview['checks']);
        $this->assertSame(['อ่านไฟล์', 'ok', 'พบข้อมูล 2 แถว'], array_values($preview['checks'][0]));
        $byLabel = array_column($preview['checks'], null, 'label');
        $this->assertSame('warning', $byLabel['ตรวจ V_CHILD_COUNT']['status']);
        $this->assertSame('ok', $byLabel['ตรวจ V_ELDERLY_COUNT']['status']);
        $this->assertSame('ok', $byLabel['เทียบกับข้อมูลในระบบ']['status']);
    }

    public function test_confirm_saves_blanks_as_null_and_records_history()
    {
        $staff = $this->staff();
        $this->upload($staff, $this->xlsx([
            ['20/01/2025', 'R001', 'ศรีสวัสดิ์ 2', 186, 142, 31, 96],
            ['20/01/2025', 'R002', null, 132, null, 22, 74],
        ]));
        $token = $this->preview($staff)['token'];

        $this->actingAs($staff)->post('/data-import/welfare/confirm', ['token' => $token])
            ->assertRedirect('/data-import/welfare')
            ->assertSessionMissing('status');

        // The page that follows carries the success card's data, with no pending preview left.
        $this->actingAs($staff)->get('/data-import/welfare')->assertInertia(fn ($page) => $page
            ->where('preview', null)
            ->where('lastImport.rowCount', 2)
            ->where('lastImport.communityCount', 2)
            ->where('lastImport.user', $staff->name));

        $this->assertSame(2, WelfareRecord::count());
        $r002 = WelfareRecord::where('community_id', Community::where('code', 'R002')->value('id'))->first();
        $this->assertNull($r002->v_child_count);
        $this->assertSame(132, $r002->v_elderly_count);

        $batch = ImportBatch::sole();
        $this->assertSame($staff->id, $batch->user_id);
        $this->assertSame(2, $batch->row_count);
        Storage::disk('local')->assertExists($batch->stored_path);
        $this->assertSame([], Storage::disk('local')->files('imports/tmp'));
    }

    public function test_reimport_replaces_only_the_same_community_and_date()
    {
        $staff = $this->staff();
        $this->upload($staff, $this->xlsx([['20/01/2025', 'R001', null, 100, 1, 1, 1], ['20/01/2025', 'R002', null, 200, 2, 2, 2]]));
        $this->actingAs($staff)->post('/data-import/welfare/confirm', ['token' => $this->preview($staff)['token']]);

        // The second upload's preview names the earlier import it will overwrite.
        $this->upload($staff, $this->xlsx([['20/01/2025', 'R001', null, 111, 1, 1, 1]]));
        $preview = $this->preview($staff);
        $this->assertSame(1, $preview['summary']['replaceCount']);
        $this->assertSame([['createdAt' => ImportBatch::sole()->created_at->toIso8601String(), 'user' => $staff->name, 'rows' => 1]], $preview['summary']['replacedBatches']);
        $this->assertSame('warning', array_column($preview['checks'], null, 'label')['เทียบกับข้อมูลในระบบ']['status']);
        $this->actingAs($staff)->post('/data-import/welfare/confirm', ['token' => $preview['token']]);

        $this->assertSame(2, WelfareRecord::count());
        $this->assertSame([111, 200], WelfareRecord::orderBy('community_id')->pluck('v_elderly_count')->all());
        $this->assertSame(1, ImportBatch::latest('id')->first()->replaced_count);
    }

    public function test_errors_block_confirmation()
    {
        $staff = $this->staff();
        $this->upload($staff, $this->xlsx([
            ['20/01/2025', 'R999', null, 1, 1, 1, 1],
            ['20/01/2568', 'R001', null, 1, 1, 1, 1],
            ['20/01/2025', 'R002', null, -3, 1.5, 'abc', 1],
            ['20/01/2025', 'R003', null, 1, 1, 1, 1],
            ['20/01/2025', 'R003', null, 2, 2, 2, 2],
            [null, null, 'ชื่ออย่างเดียว', null, null, null, null],
        ]));

        $messages = array_column($this->preview($staff)['errors'], 'message');
        $this->assertContains('ไม่พบรหัสชุมชน "R999" ในระบบ', $messages);
        $this->assertContains('ปี 2568 ดูเหมือนเป็นปี พ.ศ. กรุณากรอกปี ค.ศ.', $messages);
        $this->assertContains('-3 ต้องเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป', $messages);
        $this->assertContains('"1.5" ต้องเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป', $messages);
        $this->assertContains('ข้อมูลซ้ำกับแถวที่ 5 (COMMUNITY_ID + ASSESSMENT_DATE เดียวกัน)', $messages);
        $this->assertContains('ต้องกรอก COMMUNITY_ID', $messages);

        $this->actingAs($staff)->post('/data-import/welfare/confirm', ['token' => $this->preview($staff)['token']])
            ->assertSessionHas('error');
        $this->assertSame(0, WelfareRecord::count());
    }

    public function test_missing_column_is_an_error_and_extra_column_a_warning()
    {
        $staff = $this->staff();
        $this->upload($staff, $this->xlsx([['20/01/2025', 'R001', 1, 1, 1, 'x']], ['ASSESSMENT_DATE', 'COMMUNITY_ID', 'V_ELDERLY_COUNT', 'V_CHILD_COUNT', 'V_DISABLED_COUNT', 'หมายเหตุ']));

        $preview = $this->preview($staff);
        $this->assertContains('ไม่พบคอลัมน์ V_LOW_INCOME_COUNT', array_column($preview['errors'], 'message'));
        $this->assertNotContains('ไม่พบคอลัมน์ COMMUNITY_NAME', array_column($preview['errors'], 'message'));
        $this->assertStringContainsString('หมายเหตุ', $preview['warnings'][0]['message']);

        // Header errors stop the checklist after the header line.
        $this->assertTrue($preview['fatal']);
        $this->assertSame(['อ่านไฟล์', 'ตรวจหัวคอลัมน์'], array_column($preview['checks'], 'label'));
        $this->assertSame('error', $preview['checks'][1]['status']);
    }

    public function test_community_name_mismatch_is_only_a_warning()
    {
        $staff = $this->staff();
        $this->upload($staff, $this->xlsx([
            ['20/01/2025', 'R001', 'ชุมชนศรีสวัสดิ์ 2', 1, 1, 1, 1],
            ['20/01/2025', 'R002', 'ธัญญา 4', 1, 1, 1, 1],
        ]));

        $preview = $this->preview($staff);
        $this->assertSame([], $preview['errors']);
        $this->assertCount(1, $preview['warnings']);
        $this->assertStringContainsString('ไม่ตรงกับรหัส R002', $preview['warnings'][0]['message']);
    }

    public function test_confirm_with_a_stale_token_is_refused()
    {
        $staff = $this->staff();
        $this->upload($staff, $this->xlsx([['20/01/2025', 'R001', null, 1, 1, 1, 1]]));

        $this->actingAs($staff)->post('/data-import/welfare/confirm', ['token' => 'wrong'])->assertSessionHas('error');
        $this->assertSame(0, WelfareRecord::count());
    }

    public function test_non_excel_upload_is_rejected()
    {
        $this->upload($this->staff(), UploadedFile::fake()->create('data.csv', 1, 'text/csv'))
            ->assertSessionHasErrors(['file' => 'รองรับเฉพาะไฟล์ Excel (.xlsx, .xls)']);
    }

    public function test_upload_requires_the_group_grant()
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Health]]);

        $this->upload($staff, $this->xlsx([['20/01/2025', 'R001', null, 1, 1, 1, 1]]))
            ->assertRedirect('/data-import')
            ->assertSessionHas('error');
    }

    public function test_every_group_has_an_importer_matching_its_template()
    {
        // All 8 since 2026-11-03. A group returning null would show "อยู่ระหว่างการพัฒนา" and refuse uploads.
        foreach (WorkGroup::cases() as $group) {
            $sheet = ImportSheets::for($group);
            $this->assertNotNull($sheet, $group->value);
            $this->assertSame(ImportColumns::for($group), array_keys($sheet->rules()), $group->value);
        }
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function import(User $user, array $rows): ImportBatch
    {
        $this->upload($user, $this->xlsx($rows));
        $this->actingAs($user)->post('/data-import/welfare/confirm', ['token' => $this->preview($user)['token']]);

        return ImportBatch::latest('id')->first();
    }

    /** @return array<string, int|null> v_elderly_count per community code */
    private function elderly(): array
    {
        return WelfareRecord::with('community')->get()->mapWithKeys(fn ($r) => [$r->community->code => $r->v_elderly_count])->sortKeys()->all();
    }

    public function test_cancel_deletes_added_rows_and_restores_replaced_ones()
    {
        $staff = $this->staff();
        $first = $this->import($staff, [['20/01/2025', 'R001', null, 100, 1, 1, 1], ['20/01/2025', 'R002', null, 200, 2, 2, 2]]);
        $second = $this->import($staff, [['20/01/2025', 'R001', null, 111, null, 9, 9], ['20/01/2025', 'R003', null, 300, 3, 3, 3]]);
        $this->assertSame(['R001' => 111, 'R002' => 200, 'R003' => 300], $this->elderly());

        $this->actingAs($staff)->delete("/import-history/{$second->id}")->assertSessionHas('status');

        // R003 was new → gone. R001 was replaced → back to the first import's values, owned by it again.
        $this->assertSame(['R001' => 100, 'R002' => 200], $this->elderly());
        $r001 = WelfareRecord::where('community_id', Community::where('code', 'R001')->value('id'))->sole();
        $this->assertSame([1, 1, 1, $first->id], [$r001->v_child_count, $r001->v_disabled_count, $r001->v_low_income_count, $r001->import_batch_id]);

        $second->refresh();
        $this->assertTrue($second->isCancelled());
        $this->assertSame($staff->id, $second->cancelled_by);
        Storage::disk('local')->assertExists($second->stored_path);

        // Now the first one can be cancelled too, which empties the table.
        $this->actingAs($staff)->delete("/import-history/{$first->id}")->assertSessionHas('status');
        $this->assertSame([], $this->elderly());
    }

    public function test_a_batch_overwritten_by_a_later_one_must_wait()
    {
        $staff = $this->staff();
        $first = $this->import($staff, [['20/01/2025', 'R001', null, 100, 1, 1, 1]]);
        $second = $this->import($staff, [['20/01/2025', 'R001', null, 111, 1, 1, 1]]);

        $this->actingAs($staff)->delete("/import-history/{$first->id}")
            ->assertSessionHas('error', "ข้อมูลบางแถวของรายการนี้ถูกรายการนำเข้า #{$second->id} ทับไปแล้ว กรุณายกเลิกรายการนั้นก่อน");
        $this->assertFalse($first->refresh()->isCancelled());
        $this->assertSame(['R001' => 111], $this->elderly());

        // The history tells the page the same thing, so the button is disabled with that reason.
        $this->actingAs($staff)->get('/import-history')->assertInertia(fn ($page) => $page
            ->component('admin/ImportHistory')
            ->where('batches.data.0.cancelBlocked', null)
            ->where('batches.data.1.canCancel', true)
            ->where('batches.data.1.cancelBlocked', fn ($reason) => str_contains($reason, "#{$second->id}")));
    }

    public function test_staff_may_cancel_only_their_own_batch_within_24_hours()
    {
        $staff = $this->staff();
        $other = $this->staff();
        $batch = $this->import($staff, [['20/01/2025', 'R001', null, 100, 1, 1, 1]]);

        $this->actingAs($other)->delete("/import-history/{$batch->id}")
            ->assertSessionHas('error', 'ยกเลิกได้เฉพาะรายการที่คุณนำเข้าเอง');

        $this->travel(25)->hours();
        $this->actingAs($staff)->delete("/import-history/{$batch->id}")->assertSessionHas('error');
        $this->assertFalse($batch->refresh()->isCancelled());

        // Admin has no time limit and may cancel anyone's batch.
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->delete("/import-history/{$batch->id}")->assertSessionHas('status');
        $this->assertSame($admin->id, $batch->refresh()->cancelled_by);
        $this->assertSame(0, WelfareRecord::count());

        $this->actingAs($admin)->delete("/import-history/{$batch->id}")
            ->assertSessionHas('error', 'รายการนี้ถูกยกเลิกไปแล้ว');
    }

    public function test_cancel_requires_the_group()
    {
        $staff = $this->staff();
        $batch = $this->import($staff, [['20/01/2025', 'R001', null, 100, 1, 1, 1]]);

        // Even their own batch, once the welfare grant is taken away.
        $staff->update(['work_groups' => [WorkGroup::Health]]);
        $this->actingAs($staff)->delete("/import-history/{$batch->id}")
            ->assertSessionHas('error', 'คุณไม่มีสิทธิ์นำเข้าข้อมูลงานสวัสดิการสังคม');
        $this->assertFalse($batch->refresh()->isCancelled());

        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))->delete("/import-history/{$batch->id}")->assertForbidden();
    }

    public function test_import_history_lists_only_the_users_groups()
    {
        $batch = $this->import($this->staff(), [['20/01/2025', 'R001', null, 100, 1, 1, 1]]);

        $this->actingAs($this->staff())->get('/import-history')->assertInertia(fn ($page) => $page
            ->where('batches.total', 1)
            ->where('batches.data.0.id', $batch->id)
            ->where('batches.data.0.group', ['value' => 'welfare', 'label' => 'งานสวัสดิการสังคม'])
            ->where('batches.data.0.canCancel', false));

        $health = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Health]]);
        $this->actingAs($health)->get('/import-history')->assertInertia(fn ($page) => $page->where('batches.total', 0));

        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))->get('/import-history')->assertForbidden();
    }

    public function test_admin_can_search_all_imports_by_user_group_and_date()
    {
        $alice = User::factory()->create(['name' => 'Alice Import', 'role' => UserRole::Staff, 'work_groups' => [WorkGroup::Welfare]]);
        $bob = User::factory()->create(['name' => 'Bob', 'role' => UserRole::Staff, 'work_groups' => [WorkGroup::Welfare]]);
        $this->travelTo('2026-11-01 10:00');
        $old = $this->import($alice, [['20/01/2025', 'R001', null, 1, 1, 1, 1]]);
        $this->travelTo('2026-11-03 10:00');
        $new = $this->import($bob, [['20/01/2025', 'R002', null, 1, 1, 1, 1]]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $ids = function (string $query) use ($admin) {
            $ids = null;
            $this->actingAs($admin)->get('/import-batches'.$query)->assertInertia(function ($page) use (&$ids) {
                $ids = array_column($page->toArray()['props']['batches']['data'], 'id');
            });

            return $ids;
        };

        $this->assertSame([$new->id, $old->id], $ids(''));
        $this->assertSame([$old->id], $ids('?user=alice'));
        $this->assertSame([$new->id, $old->id], $ids('?group=welfare'));
        $this->assertSame([], $ids('?group=health'));
        $this->assertSame([$new->id], $ids('?from=2026-11-02'));
        $this->assertSame([$old->id], $ids('?to=2026-11-01'));

        $this->actingAs($alice)->get('/import-batches')->assertForbidden();
    }

    public function test_viewer_shows_the_file_as_is_with_each_rows_status()
    {
        $staff = $this->staff();
        $file = $this->xlsx([
            [Date::stringToExcel('2025-01-20'), 'R001', 'ศรีสวัสดิ์ 2', 100, 1, 1, 1, 'โน้ต'],
            ['20/01/2025', 'r002', null, 200, '-', 2, 2, null],
        ], [...self::HEADERS, 'หมายเหตุ']);
        $this->upload($staff, $file);
        $this->actingAs($staff)->post('/data-import/welfare/confirm', ['token' => $this->preview($staff)['token']]);
        $first = ImportBatch::latest('id')->first();
        $second = $this->import($staff, [['20/01/2025', 'R002', null, 222, 2, 2, 2]]);

        $json = $this->actingAs($staff)->getJson("/import-history/{$first->id}/data")->assertOk()->json();
        $this->assertFalse($json['missing']);
        $this->assertSame(['name' => 'หมายเหตุ', 'used' => false], $json['headers'][7]);
        $this->assertTrue($json['headers'][0]['used']);
        $this->assertSame(2, $json['total']);
        // As in the file: Excel serial shown as a date, the "-" and the lower-case code kept as typed.
        $this->assertSame(['2025-01-20', 'R001', 'ศรีสวัสดิ์ 2', 100, 1, 1, 1, 'โน้ต'], $json['rows'][0]['cells']);
        $this->assertSame(['20/01/2025', 'r002'], array_slice($json['rows'][1]['cells'], 0, 2));
        $this->assertSame('-', $json['rows'][1]['cells'][4]);
        $this->assertSame(['active', null], [$json['rows'][0]['status'], $json['rows'][0]['by']]);
        $this->assertSame(['replaced', $second->id], [$json['rows'][1]['status'], $json['rows'][1]['by']]);
        $this->assertSame(['active' => 1, 'replaced' => 1], $json['counts']);

        // Cancel the later import: R002 is the first import's again.
        $this->actingAs($staff)->delete("/import-history/{$second->id}");
        $json = $this->actingAs($staff)->getJson("/import-history/{$first->id}/data")->json();
        $this->assertSame(['active' => 2], $json['counts']);
        $this->assertSame(['cancelled' => 1], $this->actingAs($staff)->getJson("/import-history/{$second->id}/data")->json('counts'));
    }

    public function test_viewer_needs_the_group_and_reports_a_missing_file()
    {
        $batch = $this->import($this->staff(), [['20/01/2025', 'R001', null, 1, 1, 1, 1]]);

        $health = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Health]]);
        $this->actingAs($health)->getJson("/import-history/{$batch->id}/data")->assertForbidden();

        Storage::disk('local')->delete($batch->stored_path);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->getJson("/import-history/{$batch->id}/data")->assertOk()
            ->assertJsonPath('missing', true)
            ->assertJsonPath('batch.id', $batch->id);
    }

    public function test_admin_sees_how_many_kept_files_and_their_size()
    {
        $staff = $this->staff();
        $kept = $this->import($staff, [['20/01/2025', 'R001', null, 1, 1, 1, 1]]);
        $cancelled = $this->import($staff, [['21/01/2025', 'R001', null, 1, 1, 1, 1]]);
        $lost = $this->import($staff, [['22/01/2025', 'R001', null, 1, 1, 1, 1]]);
        $this->actingAs($staff)->delete("/import-history/{$lost->id}");
        $this->actingAs($staff)->delete("/import-history/{$cancelled->id}");
        Storage::disk('local')->delete($lost->stored_path);

        $bytes = Storage::disk('local')->size($kept->stored_path) + Storage::disk('local')->size($cancelled->stored_path);
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/import-batches?group=health')
            ->assertInertia(fn ($page) => $page
                ->where('batches.total', 0)
                // Over all batches, not the filtered list.
                ->where('storage', ['files' => 2, 'bytes' => $bytes, 'activeFiles' => 1, 'cancelledFiles' => 1, 'deletedFiles' => 0, 'deletedBytes' => 0, 'missing' => [$lost->id]]));
    }

    public function test_admin_can_delete_kept_files_of_any_batch()
    {
        $staff = $this->staff();
        $active = $this->import($staff, [['20/01/2025', 'R001', null, 1, 1, 1, 1]]);
        $cancelled = $this->import($staff, [['21/01/2025', 'R001', null, 1, 1, 1, 1]]);
        $this->actingAs($staff)->delete("/import-history/{$cancelled->id}");
        $kept = $this->import($staff, [['22/01/2025', 'R001', null, 1, 1, 1, 1]]);
        $bytes = Storage::disk('local')->size($active->stored_path) + Storage::disk('local')->size($cancelled->stored_path);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        // Staff can't; the route is admin-only.
        $this->actingAs($staff)->post('/import-batches/delete-files', ['ids' => [$active->id]])->assertForbidden();

        $this->actingAs($admin)->post('/import-batches/delete-files', ['ids' => [$active->id, $cancelled->id]])
            ->assertSessionHas('status', fn ($m) => str_starts_with($m, 'ลบไฟล์ต้นฉบับ 2 ไฟล์แล้ว'));

        Storage::disk('local')->assertMissing($active->stored_path);
        Storage::disk('local')->assertMissing($cancelled->stored_path);
        Storage::disk('local')->assertExists($kept->stored_path);
        $this->assertSame([$admin->id, $bytes], [$active->refresh()->file_deleted_by, $active->deleted_file_bytes + $cancelled->refresh()->deleted_file_bytes]);
        // The data stays.
        $this->assertSame(2, WelfareRecord::count());

        // Deleted files are counted as deleted, not missing; the viewer says so; the status filter finds them.
        $this->actingAs($admin)->get('/import-batches?status=file_deleted')->assertInertia(fn ($page) => $page
            ->where('batches.total', 2)
            ->where('batches.data.0.fileDeletedBy', $admin->name)
            ->where('batches.data.0.fileBytes', null)
            ->where('storage.files', 1)
            ->where('storage.deletedFiles', 2)
            ->where('storage.deletedBytes', $bytes)
            ->where('storage.missing', []));
        $this->actingAs($admin)->getJson("/import-history/{$active->id}/data")->assertJsonPath('fileDeleted', true);

        // A second delete of the same file is skipped, and the active batch can still be cancelled.
        $this->actingAs($admin)->post('/import-batches/delete-files', ['ids' => [$active->id]])->assertSessionHas('status', 'ไฟล์ที่เลือกถูกลบไปก่อนแล้ว');
        $this->actingAs($admin)->delete("/import-history/{$kept->id}")->assertSessionHas('status');
        $this->actingAs($admin)->delete("/import-history/{$active->id}")->assertSessionHas('status');
        $this->assertSame(0, WelfareRecord::count());
    }

    public function test_community_with_imported_data_cannot_be_deleted()
    {
        $staff = $this->staff();
        $this->upload($staff, $this->xlsx([['20/01/2025', 'R001', null, 1, 1, 1, 1]]));
        $this->actingAs($staff)->post('/data-import/welfare/confirm', ['token' => $this->preview($staff)['token']]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $community = Community::where('code', 'R001')->first();
        $this->actingAs($admin)->delete("/communities/{$community->id}")->assertSessionHas('error');
        $this->assertModelExists($community);
    }

    public function test_confirm_dialog_names_the_criteria_set_per_fiscal_year()
    {
        ScoringCriteriaSet::create(['name' => 'ชุดแรก', 'start_fiscal_year' => 2568, 'is_locked' => false, 'weights' => CriteriaSchema::defaults()]);
        ScoringCriteriaSet::create(['name' => 'ชุดใหม่', 'start_fiscal_year' => 2570, 'is_locked' => false, 'weights' => CriteriaSchema::defaults()]);
        $staff = $this->staff();
        $this->upload($staff, $this->xlsx([
            ['2025-01-20', 'R001', null, 1, 1, 1, 1], // 2568
            ['2026-11-01', 'R002', null, 1, 1, 1, 1], // 2570
            ['2025-12-01', 'R003', null, 1, 1, 1, 1], // 2569 → still ชุดแรก
        ]));

        $this->actingAs($staff)->get('/data-import/welfare')->assertInertia(fn ($page) => $page->where('criteriaByYear', [
            ['year' => 2568, 'set' => 'ชุดแรก'],
            ['year' => 2569, 'set' => 'ชุดแรก'],
            ['year' => 2570, 'set' => 'ชุดใหม่'],
        ]));
    }
}
