<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Scoring\CriteriaSchema;
use App\Scoring\KpiCalculator;
use App\Scoring\McrEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Mcr2030KpiTest extends TestCase
{
    use RefreshDatabase;

    public function test_kpi_formulas_follow_spec_5_6_and_5_9()
    {
        $k = KpiCalculator::forCommunity([
            'c6_1_response_vehicle_adequacy_score' => 4, 'c6_2_lifesaving_score' => 5, 'c6_3_readiness_score' => 20, // 20% → 1
            'c6_4_hazard_equipment_adequacy_score' => null, // N/A dropped
            'c7_1_flood_score' => 4, 'c7_1_fire_score' => 2, 'c7_1_storm_score' => 0, 'c7_2_safe_place_score' => 3, // storm not used
            'c8_2_response_hours' => 10, // >6–12 → 2
            'v_warning_access_gap_score' => 1, // 5 − 1
            'v_infra_gap_count_flood' => 2,
            'c5_1_greenblue_score' => 2, 'c5_2_nbs_score' => 4, 'c5_3_maintenance_score' => null, // (0.7+1.4)/0.7
            'v_elderly_count' => 200, 'kpi_elderly_plan_count' => 100, // 50%
            'v_disabled_count' => 0, 'kpi_disabled_plan_count' => 0, // denominator 0 → that group N/A
            'v_child_count' => 100, 'kpi_child_plan_count' => 90, // 90% → average 70% → 4
        ]);

        $this->assertEqualsWithDelta((4 + 5 + 1) / 3, $k['KPI_C6']['score'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $k['KPI_C7']['score'], 1e-9);
        $this->assertSame(2.0, $k['KPI_C8_2']['score']);
        $this->assertSame(10.0, $k['KPI_C8_2']['raw']);
        $this->assertSame(4.0, $k['KPI_WARNING']['score']);
        $this->assertSame(3.0, $k['KPI_INFRA_FLOOD']['score']);
        $this->assertEqualsWithDelta((0.35 * 2 + 0.35 * 4) / 0.7, $k['KPI_C5']['score'], 1e-9);
        $this->assertEqualsWithDelta(70.0, $k['KPI_VULNERABLE']['raw'], 1e-9);
        $this->assertSame(4.0, $k['KPI_VULNERABLE']['score']);
        $this->assertNull($k['KPI_MEDICAL']['score']);
        $this->assertNull($k['KPI_LOW_INCOME']['score']);
    }

    public function test_mcr_evidence_never_finds_responsible_agency_and_treats_school_drr_as_optional()
    {
        $e = McrEvidence::forCommunity([
            'c8_1_formal_network_count' => 3, 'c8_3_network_types_score' => 2, 'c8_4_joint_experience_score' => 4,
            'c4_1_volunteer_per_1000' => 5, 'c4_2_training_count' => 1, 'c4_3_risk_comm_score' => 2,
            'c4_4_school_drr_education_score' => null,
        ]);

        $this->assertSame('partial', $e['E1']['status']);
        $this->assertStringContainsString('ยังไม่มีช่องกรอก', $e['E1']['missing'][0]);
        $this->assertSame('full', $e['E6']['status']); // C4_4 empty doesn't count against it
        $this->assertSame('none', $e['E10']['status']);
        $this->assertSame('ยังไม่พบข้อมูลประกอบ', $e['E10']['sentence']);
        $this->assertSame('ระบบพบข้อมูลเกี่ยวกับเครือข่ายที่เป็นทางการ ความหลากหลายของภาคส่วน และประสบการณ์การทำงานร่วมกันของชุมชน', $e['E1']['sentence']);
    }

    public function test_every_role_can_view_it()
    {
        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/mcr2030-kpi')->assertOk();
        }
    }

    public function test_city_percent_kpis_pool_counts_and_score_kpis_are_population_weighted()
    {
        ScoringCriteriaSet::create(['name' => 'ชุด', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()]);
        foreach ([['R001', 1000, 4, 10, 100], ['R002', 3000, 2, 90, 100]] as [$code, $pop, $drill, $ready, $evac]) {
            $id = Community::create(['code' => $code, 'name' => "ชุมชน $code"])->id;
            $base = ['community_id' => $id, 'assessment_date' => '2025-01-20'];
            DB::table('disaster_records')->insert($base + ['population' => $pop, 'c7_3_drill_score' => $drill]);
            DB::table('health_records')->insert($base + ['v_assisted_evacuation_count' => $evac]);
            DB::table('kpi_records')->insert($base + ['kpi_assisted_evac_ready_count' => $ready]);
        }

        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
            ->get('/mcr2030-kpi')
            ->assertInertia(fn ($page) => $page
                ->component('admin/Mcr2030Kpi')
                ->where('city.kpis.KPI_C7_3.score', (4 * 1000 + 2 * 3000) / 4000) // 2.5
                ->where('city.kpis.KPI_ASSIST.raw', 50) // (10 + 90) / (100 + 100)
                ->where('city.kpis.KPI_ASSIST.score', 3)
                ->where('city.kpis.KPI_ASSIST.hasData', true)
                ->where('city.kpis.KPI_MEDICAL.hasData', false)
                ->where('city.mcr.E1.none', 2) // no C8 data, and RESPONSIBLE_AGENCY has no column
                ->where('city.mcr.E2.partial', 2)
                ->where('communities.0.kpis.KPI_ASSIST.raw', 10)
                ->where('communities.0.kpis.KPI_ASSIST.score', 1));
    }
}
