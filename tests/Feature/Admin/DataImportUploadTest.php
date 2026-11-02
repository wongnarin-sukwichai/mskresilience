<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Import\ImportColumns;
use App\Import\ImportSheets;
use App\Models\Community;
use App\Models\ImportBatch;
use App\Models\User;
use App\Models\WelfareRecord;
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

    public function test_groups_without_an_importer_refuse_uploads()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->post('/data-import/health/validate', ['file' => $this->xlsx([])])
            ->assertSessionHas('error', 'ยังไม่รองรับการนำเข้าข้อมูลงานสาธารณสุข');
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
}
