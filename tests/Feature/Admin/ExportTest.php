<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Scoring\CriteriaSchema;
use App\Support\TorMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Phase 4: Excel exports (TOR 4.4-4), the per-community funding-evidence report (TOR 4.1.1(5)-3) and the admin
 * ตารางเทียบ TOR page.
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    private function seedCommunity(string $code, string $date, int $exposed, int $budget): void
    {
        $community = Community::firstOrCreate(['code' => $code], ['name' => "ชุมชน $code"]);
        $rows = [
            'disaster_records' => [
                'population' => 1000, 'households' => 400, 'area_km2' => 0.7,
                'e_numerator_count_flood' => $exposed, 'e_numerator_count_fire' => $exposed, 'e_numerator_count_storm' => $exposed,
                'e_numerator_count_heat' => $exposed, 'e_numerator_count_pm25' => $exposed,
                'c4_1_volunteer_per_1000' => 5, 'c4_2_training_count' => 1, 'c6_1_response_vehicle_adequacy_score' => 2,
                'c6_2_lifesaving_score' => 2, 'c6_3_readiness_score' => 40, 'c6_4_hazard_equipment_adequacy_score' => 2,
                'c7_1_flood_score' => 2, 'c7_1_fire_score' => 2, 'c7_1_storm_score' => 2, 'c7_2_safe_place_score' => 2, 'c7_3_drill_score' => 2,
                'c8_1_formal_network_count' => 2, 'c8_2_response_hours' => 5, 'c8_3_network_types_score' => 2, 'c8_4_joint_experience_score' => 2,
                'r1_recovery_plan_score' => 3, 'r2_build_back_better_score' => 3, 'r3_socioeconomic_recov_score' => 3,
                'r4_vulner_group_recov_score' => 3, 'r5_learning_sys_improv_score' => 3, 'm_budget_avg_3y' => $budget,
            ],
            'welfare_records' => ['v_elderly_count' => 150, 'v_child_count' => 150, 'v_disabled_count' => 50, 'v_low_income_count' => 100],
            'health_records' => ['v_bedridden_count' => 10, 'v_medical_dependent_count' => 10, 'v_assisted_evacuation_count' => 20, 'v_communication_assistance_count' => 10, 'v_ncd_count' => 100],
            'education_records' => ['c4_4_school_drr_education_score' => 2],
            'engineering_records' => ['v_infra_gap_count_flood' => 2, 'v_infra_gap_count_fire' => 2, 'v_infra_gap_count_storm' => 2, 'v_infra_gap_count_heat' => 2, 'v_infra_gap_count_pm25' => 2, 'c5_1_greenblue_score' => 2, 'c5_2_nbs_score' => 2, 'c5_3_maintenance_score' => 2],
            'public_relations_records' => ['v_warning_access_gap_score' => 2, 'c4_3_risk_comm_score' => 2],
        ];
        foreach ($rows as $table => $values) {
            DB::table($table)->insert($values + ['community_id' => $community->id, 'assessment_date' => $date]);
        }
    }

    private function seedCity(): void
    {
        ScoringCriteriaSet::create(['name' => 'ชุดหลัก', 'start_fiscal_year' => 2567, 'is_locked' => false, 'weights' => CriteriaSchema::defaults()]);
        $this->seedCommunity('R001', '2025-01-20', exposed: 500, budget: 200000);
        $this->seedCommunity('R001', '2026-01-20', exposed: 900, budget: 200000);
        $this->seedCommunity('R002', '2026-01-20', exposed: 50, budget: 2000000);
        DB::table('disaster_events')->insert([
            'event_id' => 'EV0001', 'community_id' => Community::where('code', 'R001')->value('id'), 'event_start' => '2025-11-05',
            'primary_hazard' => 'FLOOD', 'l1_houses_damaged_count' => 12, 'l3_damage_value_thb' => 350000,
            'f2_event_response_cost_thb' => 100, 'f3_event_recovery_cost_thb' => 200, 'f4_event_risk_reduction_budget_thb' => 300,
            'event_description' => 'น้ำท่วมขัง',
        ]);
    }

    /** @return list<list<mixed>> the cells of the downloaded workbook's sheet */
    private function sheet($response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->streamedContent());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray();
        unlink($path);

        return $rows;
    }

    public function test_risk_export_is_a_real_xlsx_ranked_riskiest_first()
    {
        $this->seedCity();
        $response = $this->actingAs(User::factory()->create(['role' => UserRole::Member]))->get('/export/risk');
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $rows = $this->sheet($response);
        $this->assertStringContainsString('ความเสี่ยงรายชุมชน', $rows[0][0]);
        $this->assertSame('รหัส', $rows[3][0]);
        $this->assertSame(['R001', 'R002'], [$rows[4][0], $rows[5][0]]);
        $this->assertIsNumeric($rows[4][5]); // HEVC stays a number
    }

    public function test_events_export_honours_the_page_filters()
    {
        $this->seedCity();
        $user = User::factory()->create(['role' => UserRole::Member]);

        $rows = $this->sheet($this->actingAs($user)->get('/export/events?year=2569&community=R001'));
        $this->assertSame('EV0001', $rows[4][0]);
        $this->assertEquals(350000, $rows[4][9]);

        $rows = $this->sheet($this->actingAs($user)->get('/export/events?hazard=FIRE'));
        $this->assertCount(4, $rows); // title, note, blank, header: no events
    }

    public function test_kpi_and_flag_exports_download()
    {
        $this->seedCity();
        $user = User::factory()->create(['role' => UserRole::Member]);
        $this->actingAs($user)->get('/export/kpi')->assertOk();
        $rows = $this->sheet($this->actingAs($user)->get('/export/flags'));
        $this->assertSame('ประเภท', $rows[3][0]);
    }

    public function test_funding_report_shows_one_community_with_its_losses_by_fiscal_year()
    {
        $this->seedCity();
        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
            ->get('/reports/funding/r001')
            ->assertInertia(fn ($page) => $page
                ->component('admin/FundingReport')
                ->where('community.code', 'R001')
                ->where('community.budgetPerPerson', 200)
                ->where('city.rank', 0)
                ->where('lossByYear.0.year', 2569)
                ->where('lossByYear.0.houses', 12)
                ->where('lossByYear.0.f4', 300)
                ->where('events.0.id', 'EV0001')
                ->has('mcr.essentials', 10)
                ->missing('community.inputs'));
    }

    public function test_funding_report_for_an_unknown_community_is_404()
    {
        $this->seedCity();
        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))->get('/reports/funding/R999')->assertNotFound();
    }

    public function test_tor_matrix_is_admin_only_and_every_link_resolves()
    {
        foreach (TorMatrix::sections() as $section) {
            foreach ($section['items'] as $item) {
                $this->assertArrayHasKey($item['status'], TorMatrix::STATUSES, $item['ref']);
                foreach ($item['where'] as $w) {
                    if (isset($w['route'])) {
                        $this->assertTrue(Route::has($w['route']), "{$item['ref']}: {$w['route']}");
                    }
                }
            }
        }

        $this->actingAs(User::factory()->create(['role' => UserRole::Staff, 'work_groups' => ['welfare']]))->get('/tor-matrix')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/tor-matrix')
            ->assertInertia(fn ($page) => $page->component('admin/TorMatrix')->has('sections')->has('statuses', 4));
    }
}
