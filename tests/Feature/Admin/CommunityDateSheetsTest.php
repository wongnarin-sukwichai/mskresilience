<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Import\ImportColumns;
use App\Import\ImportExamples;
use App\Import\ImportSheets;
use App\Import\Sheets\ImportSheet;
use App\Models\Community;
use App\Models\DisasterRecord;
use App\Models\EducationRecord;
use App\Models\EngineeringRecord;
use App\Models\HealthRecord;
use App\Models\ImportBatch;
use App\Models\KpiRecord;
use App\Models\PublicRelationsRecord;
use App\Models\User;
use App\Models\WelfareRecord;
use Database\Seeders\CommunitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sheets built on CommunityDateSheet (one row per community per ASSESSMENT_DATE). The detailed validator,
 * history and cancel rules are covered with welfare in DataImportUploadTest; this checks each sheet end to end.
 */
class CommunityDateSheetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CommunitySeeder::class);
    }

    public static function sheets(): array
    {
        return [
            'disaster' => [WorkGroup::Disaster, DisasterRecord::class],
            'welfare' => [WorkGroup::Welfare, WelfareRecord::class],
            'health' => [WorkGroup::Health, HealthRecord::class],
            'education' => [WorkGroup::Education, EducationRecord::class],
            'engineering' => [WorkGroup::Engineering, EngineeringRecord::class],
            'public relations' => [WorkGroup::PublicRelations, PublicRelationsRecord::class],
            'kpi' => [WorkGroup::Kpi, KpiRecord::class],
        ];
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function xlsx(WorkGroup $group, array $rows): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([ImportColumns::for($group), ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, "{$group->value}.xlsx", null, null, true);
    }

    /**
     * A valid row: every count column = $count, every score column = $score.
     *
     * @return list<mixed>
     */
    private function row(WorkGroup $group, string $date, string $code, int $count, int $score): array
    {
        $values = array_map(fn ($rule) => $rule === ImportSheet::SCORE ? $score : $count, array_slice(ImportSheets::for($group)->rules(), 3));

        return [$date, $code, null, ...array_values($values)];
    }

    private function preview(User $user, WorkGroup $group): array
    {
        $props = null;
        $this->actingAs($user)->get("/data-import/{$group->value}")->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props['preview'];
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function import(User $user, WorkGroup $group, array $rows): ImportBatch
    {
        $this->actingAs($user)->post("/data-import/{$group->value}/validate", ['file' => $this->xlsx($group, $rows)]);
        $preview = $this->preview($user, $group);
        $this->assertSame([], $preview['errors']);
        $this->actingAs($user)->post("/data-import/{$group->value}/confirm", ['token' => $preview['token']]);

        return ImportBatch::latest('id')->firstOrFail();
    }

    #[DataProvider('sheets')]
    public function test_rules_match_the_template_columns(WorkGroup $group)
    {
        $this->assertSame(ImportColumns::for($group), array_keys(ImportSheets::for($group)->rules()));
    }

    #[DataProvider('sheets')]
    public function test_the_web_examples_pass_validation(WorkGroup $group)
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->post("/data-import/{$group->value}/validate", ['file' => $this->xlsx($group, ImportExamples::for($group))]);

        $this->assertSame([], $this->preview($admin, $group)['errors']);
    }

    #[DataProvider('sheets')]
    public function test_import_replace_and_cancel(WorkGroup $group, string $model)
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [$group]]);
        // The value column checked below: the first whole-number one (count or score).
        $rules = ImportSheets::for($group)->rules();
        $columns = [strtolower(array_key_first(array_filter($rules, fn ($r) => in_array($r, [ImportSheet::COUNT, ImportSheet::SCORE], true))))];

        $first = $this->import($staff, $group, [$this->row($group, '20/01/2025', 'R001', 7, 3), $this->row($group, '20/01/2025', 'R002', 7, 3)]);
        $this->assertSame(2, $model::count());
        $this->assertSame($first->id, $model::first()->import_batch_id);

        $second = $this->import($staff, $group, [$this->row($group, '20/01/2025', 'R001', 9, 5)]);
        $this->assertSame(1, $second->replaced_count);
        $r001 = $model::where('community_id', Community::where('code', 'R001')->value('id'))->sole();
        $this->assertContains($r001->{$columns[0]}, [9, 5]);

        // Cancelling the second puts the first one's values back.
        $this->actingAs($staff)->delete("/import-history/{$second->id}")->assertSessionHas('status');
        $r001->refresh();
        $this->assertContains($r001->{$columns[0]}, [7, 3]);
        $this->assertSame($first->id, $r001->import_batch_id);

        // Imported data blocks deleting the community.
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->delete("/communities/{$r001->community_id}")->assertSessionHas('error');

        $this->actingAs($staff)->delete("/import-history/{$first->id}")->assertSessionHas('status');
        $this->assertSame(0, $model::count());
    }

    public function test_disaster_decimals_percent_and_text()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $group = WorkGroup::Disaster;
        $row = fn (array $override) => array_values(array_replace(
            array_combine(ImportColumns::for($group), $this->row($group, '20/01/2025', 'R001', 7, 3)),
            $override,
        ));

        $this->actingAs($admin)->post('/data-import/disaster/validate', ['file' => $this->xlsx($group, [
            $row(['COMMUNITY_ID' => 'R001', 'AREA_KM2' => 0.725, 'C6_3_READINESS_SCORE' => 101, 'C8_2_RESPONSE_HOURS' => -2, 'M_BUDGET_AVG_3Y' => 'abc']),
        ])]);
        $messages = array_column($this->preview($admin, $group)['errors'], 'message');
        $this->assertContains('0.725 มีทศนิยมเกิน 2 ตำแหน่ง', $messages);
        $this->assertContains('101 ต้องเป็นตัวเลข 0–100 (ร้อยละ)', $messages);
        $this->assertContains('-2 ต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป', $messages);
        $this->assertContains('"abc" ต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป', $messages);

        // Valid: 2 decimals (including a float like 0.1 + 0.2), a percent, budget with a thousands separator, text.
        $this->import($admin, $group, [$row([
            'TAMBON' => ' ตลาด ', 'AREA_KM2' => 0.1 + 0.2, 'C4_1_VOLUNTEER_PER_1000' => 10.5,
            'C6_3_READINESS_SCORE' => 80, 'C8_2_RESPONSE_HOURS' => 72, 'M_BUDGET_AVG_3Y' => '1,250,000.50',
        ])]);
        $record = DisasterRecord::sole();
        $this->assertSame('ตลาด', $record->tambon);
        $this->assertSame(['0.30', '10.50', '80.00', '72.00', '1250000.50'], [
            $record->area_km2, $record->c4_1_volunteer_per_1000, $record->c6_3_readiness_score, $record->c8_2_response_hours, $record->m_budget_avg_3y,
        ]);
    }

    public function test_kpi_above_its_group_only_warns()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        // Groups: health on 15/01 (R001: 5 medical-dependent, 8 bedridden, 9 assisted-evacuation), welfare not imported.
        $this->import($admin, WorkGroup::Health, [['15/01/2025', 'R001', null, 8, 5, 9, 1, 1]]);
        $kpi = fn (string $date, string $code, int $medical) => [$date, $code, null, 1, 1, 1, 1, 1, $medical, 8, 10];

        $this->actingAs($admin)->post('/data-import/kpi/validate', ['file' => $this->xlsx(WorkGroup::Kpi, [
            $kpi('20/01/2025', 'R001', 10), // medical 10 > 5, bedridden 8 = 8 ok, assisted 10 > 9
            $kpi('10/01/2025', 'R001', 10), // before the health data: not compared
            $kpi('20/01/2025', 'R002', 10), // no health data for R002
        ])]);
        $preview = $this->preview($admin, WorkGroup::Kpi);

        $this->assertSame([], $preview['errors']);
        $this->assertContains(
            'KPI_MEDICAL_PLAN_READY_COUNT = 10 มากกว่า V_MEDICAL_DEPENDENT_COUNT = 5 (งานสาธารณสุข วันที่ประเมิน 15/01/2025) ร้อยละจะเกิน 100',
            array_column($preview['warnings'], 'message'),
        );
        $overs = array_filter($preview['warnings'], fn ($w) => str_contains($w['message'], 'มากกว่า'));
        $this->assertSame([[2, 'KPI_MEDICAL_PLAN_READY_COUNT'], [2, 'KPI_ASSISTED_EVAC_READY_COUNT']], array_values(array_map(fn ($w) => [$w['row'], $w['column']], $overs)));

        $checks = array_column($preview['checks'], null, 'label');
        $this->assertSame(['warning', 'เกินกลุ่มเป้าหมาย 1 แถว · ไม่มีข้อมูลกลุ่มให้เทียบ 2 แถว'], array_values(array_slice($checks['เทียบ KPI_MEDICAL_PLAN_READY_COUNT กับ V_MEDICAL_DEPENDENT_COUNT (งานสาธารณสุข)'], 1)));
        $this->assertSame('ok', $checks['เทียบ KPI_BEDRIDDEN_PLAN_READY_COUNT กับ V_BEDRIDDEN_COUNT (งานสาธารณสุข)']['status']);
        $this->assertSame(['ok', 'ยังไม่มีข้อมูลกลุ่มให้เทียบ'], array_values(array_slice($checks['เทียบ KPI_ELDERLY_PLAN_COUNT กับ V_ELDERLY_COUNT (งานสวัสดิการสังคม)'], 1)));
        $this->assertArrayNotHasKey('เทียบ KPI_LOW_INCOME_EXIT_VERIFIED_COUNT กับ V_LOW_INCOME_COUNT (งานสวัสดิการสังคม)', $checks);

        // Only a warning: it can still be confirmed.
        $this->actingAs($admin)->post('/data-import/kpi/confirm', ['token' => $preview['token']]);
        $this->assertSame(3, KpiRecord::count());
    }

    #[DataProvider('sheets')]
    public function test_values_out_of_range_are_errors(WorkGroup $group)
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rules = ImportSheets::for($group)->rules();
        $hasScore = in_array(ImportSheet::SCORE, $rules, true);

        $this->actingAs($admin)->post("/data-import/{$group->value}/validate", ['file' => $this->xlsx($group, [
            $this->row($group, '20/01/2025', 'R001', -1, 6),
        ])]);

        $messages = array_column($this->preview($admin, $group)['errors'], 'message');
        if (in_array(ImportSheet::COUNT, $rules, true)) {
            $this->assertContains('-1 ต้องเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป', $messages);
        }
        if ($hasScore) {
            $this->assertContains('6 ต้องเป็นจำนวนเต็ม 0–5', $messages);
        }
    }
}
