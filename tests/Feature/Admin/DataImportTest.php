<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Import\ImportColumns;
use App\Import\ImportExamples;
use App\Import\TemplateBuilder;
use App\Models\Community;
use App\Models\User;
use Database\Seeders\CommunitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    public function test_upload_page_lists_the_template_columns()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/data-import/welfare')->assertInertia(fn ($page) => $page
            ->where('columns', ImportColumns::for(WorkGroup::Welfare))
            ->where('descriptions.3', 'จำนวนผู้สูงอายุ')
            ->where('exampleRows', ImportExamples::for(WorkGroup::Welfare)));
    }

    public function test_every_example_row_lines_up_with_its_columns()
    {
        foreach (WorkGroup::cases() as $group) {
            $rows = ImportExamples::for($group);
            $this->assertCount(3, $rows, $group->value);
            foreach ($rows as $row) {
                $this->assertCount(count(ImportColumns::for($group)), $row, $group->value);
            }
        }
    }

    public function test_template_has_headers_only_and_a_community_dropdown()
    {
        $this->seed(CommunitySeeder::class);
        $staff = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Welfare]]);

        $response = $this->actingAs($staff)->get('/data-import/welfare/template');
        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);
        unlink($path);

        $data = $book->getSheet(0);
        $this->assertSame('งานสวัสดิการสังคม', $data->getTitle());
        $this->assertSame(
            ['ASSESSMENT_DATE', 'COMMUNITY_ID', 'COMMUNITY_NAME', 'V_ELDERLY_COUNT', 'V_CHILD_COUNT', 'V_DISABLED_COUNT', 'V_LOW_INCOME_COUNT', null],
            $data->rangeToArray('A1:H1')[0],
        );

        // Rows below the header carry formatting only, never values.
        $this->assertSame([], array_filter(array_merge(...$data->rangeToArray('A2:H'.(TemplateBuilder::DATA_ROWS + 1))), fn ($v) => $v !== null));
        $this->assertSame(DataValidation::TYPE_LIST, $data->getCell('B2')->getDataValidation()->getType());

        // Headers carry the client's description as a hover comment.
        $this->assertStringContainsString('จำนวนผู้สูงอายุ', $data->getComment('D1')->getText()->getPlainText());

        $ref = $book->getSheetByName(TemplateBuilder::REFERENCE_SHEET);
        $this->assertSame(Community::count() + 1, $ref->getHighestDataRow());
        $this->assertSame('R001', $ref->getCell('A2')->getValue());
    }

    public function test_every_template_builds_with_its_headers()
    {
        $this->seed(CommunitySeeder::class);

        foreach (WorkGroup::cases() as $group) {
            $columns = ImportColumns::for($group);
            $sheet = TemplateBuilder::build($group)->getSheet(0);
            $this->assertSame($columns, $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0], $group->value);
        }
    }

    public function test_event_template_starts_with_staff_typed_event_id()
    {
        $this->assertSame('EVENT_ID', ImportColumns::for(WorkGroup::DisasterEvent)[0]);
    }

    public function test_staff_cannot_download_an_ungranted_template()
    {
        $staff = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::Health]]);

        $this->actingAs($staff)->get('/data-import/kpi/template')
            ->assertRedirect('/data-import')
            ->assertSessionHas('error');

        $member = User::factory()->create(['role' => UserRole::Member]);
        $this->actingAs($member)->get('/data-import/health/template')->assertStatus(403);
    }

    public function test_unknown_group_is_not_found()
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/data-import/not-a-group')->assertStatus(404);
    }
}
