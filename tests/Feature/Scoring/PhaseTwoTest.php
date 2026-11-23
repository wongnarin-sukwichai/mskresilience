<?php

namespace Tests\Feature\Scoring;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Scoring\CriteriaSchema;
use App\Scoring\HevcCalculator;
use App\Scoring\McrEvidence;
use App\Scoring\RiskTrend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 of the TOR rework: V people/system split (คุณลักษณะ 2.2-3), HEVC trend + continuous-high (4.1.2,
 * คุณลักษณะ 2.6-1/2), MCR evidence coverage 0–10 and mapping (4.1.1(3)-2, 11.3), and the page data behind the
 * R1–R5 radar and the damage-vs-HEVC scatter (4.1.1(4), 4.1.1(5)-2).
 */
class PhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, mixed>> one community's 6 sheets, by table */
    private function tables(int $exposed = 20, int $elderly = 186): array
    {
        return [
            'disaster_records' => [
                'population' => 1240, 'households' => 460, 'area_km2' => 0.72,
                'e_numerator_count_flood' => $exposed, 'e_numerator_count_fire' => $exposed, 'e_numerator_count_storm' => $exposed,
                'e_numerator_count_heat' => $exposed, 'e_numerator_count_pm25' => $exposed,
                'c4_1_volunteer_per_1000' => 10.5, 'c4_2_training_count' => 1,
                'c6_1_response_vehicle_adequacy_score' => 4, 'c6_2_lifesaving_score' => 5, 'c6_3_readiness_score' => 20,
                'c6_4_hazard_equipment_adequacy_score' => 4, 'c7_1_flood_score' => 4, 'c7_1_fire_score' => 4, 'c7_1_storm_score' => 4,
                'c7_2_safe_place_score' => 4, 'c7_3_drill_score' => 4, 'c8_1_formal_network_count' => 3, 'c8_2_response_hours' => 10,
                'c8_3_network_types_score' => 4, 'c8_4_joint_experience_score' => 4,
                'r1_recovery_plan_score' => 4, 'r2_build_back_better_score' => 1, 'r3_socioeconomic_recov_score' => 3,
                'r4_vulner_group_recov_score' => 3, 'r5_learning_sys_improv_score' => 4, 'm_budget_avg_3y' => 850000,
            ],
            'welfare_records' => ['v_elderly_count' => $elderly, 'v_child_count' => 142, 'v_disabled_count' => 31, 'v_low_income_count' => 96],
            'health_records' => ['v_bedridden_count' => 8, 'v_medical_dependent_count' => 5, 'v_assisted_evacuation_count' => 14, 'v_communication_assistance_count' => 6, 'v_ncd_count' => 86],
            'education_records' => ['c4_4_school_drr_education_score' => 0],
            'engineering_records' => [
                'v_infra_gap_count_flood' => 1, 'v_infra_gap_count_fire' => 1, 'v_infra_gap_count_storm' => 2, 'v_infra_gap_count_heat' => 2,
                'v_infra_gap_count_pm25' => 2, 'c5_1_greenblue_score' => 2, 'c5_2_nbs_score' => 2, 'c5_3_maintenance_score' => 2,
            ],
            'public_relations_records' => ['v_warning_access_gap_score' => 1, 'c4_3_risk_comm_score' => 1],
        ];
    }

    private function seedCommunity(string $code, string $date, int $exposed = 20, int $elderly = 186): Community
    {
        $community = Community::firstOrCreate(['code' => $code], ['name' => "ชุมชน $code"]);
        foreach ($this->tables($exposed, $elderly) as $table => $values) {
            DB::table($table)->insert($values + ['community_id' => $community->id, 'assessment_date' => $date]);
        }

        return $community;
    }

    private function defaultSet(): void
    {
        ScoringCriteriaSet::create(['name' => 'ชุดหลัก', 'start_fiscal_year' => 2567, 'is_locked' => false, 'weights' => CriteriaSchema::defaults()]);
    }

    public function test_v_splits_into_people_and_system_without_changing_v()
    {
        $inputs = array_merge(...array_values($this->tables()));
        $result = (new HevcCalculator($inputs, CriteriaSchema::defaults()))->calculate();
        $flood = $result['hazards']['FLOOD'];
        $w = CriteriaSchema::defaults()['v_hazard']['FLOOD'];
        $g = $result['groups'];

        $people = ($w['V_SOCIAL'] * $g['V_SOCIAL'] + $w['V_HEALTH'] * $g['V_HEALTH'] + $w['V_ASSIST'] * $g['V_ASSIST'])
            / ($w['V_SOCIAL'] + $w['V_HEALTH'] + $w['V_ASSIST']);
        $system = ($w['V_WARNING'] * $g['V_WARNING'] + $w['V_INFRA'] * 1) / ($w['V_WARNING'] + $w['V_INFRA']);
        $this->assertEqualsWithDelta($people, $flood['vPeople'], 1e-9);
        $this->assertEqualsWithDelta($system, $flood['vSystem'], 1e-9);
        // V itself is the full weighted average, unchanged by the split.
        $sumW = array_sum($w);
        $this->assertEqualsWithDelta(($people * ($sumW - $w['V_WARNING'] - $w['V_INFRA']) + $system * ($w['V_WARNING'] + $w['V_INFRA'])) / $sumW, $flood['v'], 1e-9);
    }

    public function test_trend_marks_years_without_new_data_and_counts_consecutive_high_years()
    {
        $this->defaultSet();
        // R001: data every year. R002: no record in FY2568, so its 2568 point carries 2567 forward (stale).
        $this->seedCommunity('R001', '2024-01-20');
        $this->seedCommunity('R001', '2025-01-20', exposed: 600);
        $this->seedCommunity('R001', '2026-01-20', exposed: 900);
        $this->seedCommunity('R002', '2024-01-20');
        $this->seedCommunity('R002', '2026-01-20');

        $series = RiskTrend::series();
        $this->assertSame([2567, 2568, 2569], $series['years']);
        $this->assertFalse($series['communities']['R002'][2567]['stale']);
        $this->assertTrue($series['communities']['R002'][2568]['stale']);
        $this->assertGreaterThan($series['communities']['R001'][2567]['multi'], $series['communities']['R001'][2569]['multi']);

        $this->assertSame(2, RiskTrend::consecutiveHigh([2567 => ['level' => 'low', 'stale' => false], 2568 => ['level' => 'high', 'stale' => false], 2569 => ['level' => 'very_high', 'stale' => false]]));
        $this->assertSame(0, RiskTrend::consecutiveHigh([2568 => ['level' => 'high', 'stale' => false], 2569 => ['level' => 'moderate', 'stale' => false]]));
        // A stale year isn't evidence of another high year.
        $this->assertSame(1, RiskTrend::consecutiveHigh([2567 => ['level' => 'high', 'stale' => false], 2568 => ['level' => 'high', 'stale' => true], 2569 => ['level' => 'high', 'stale' => false]]));
    }

    public function test_mcr_coverage_counts_half_for_partial_and_skips_event_topics_without_events()
    {
        $values = ['m_budget_avg_3y' => 850000];
        // E3 = budget + 5 event-based topics. With events in the system but none recorded → partial.
        $this->assertSame('partial', McrEvidence::forCommunity($values, hasEvents: true)['E3']['status']);
        // A community that had no event isn't missing event evidence → full.
        $screening = McrEvidence::forCommunity($values, hasEvents: false);
        $this->assertSame('full', $screening['E3']['status']);
        $this->assertSame(1.0, $screening['E3']['points']);

        $this->assertSame(0.5 + 1.0, McrEvidence::coverage(['E1' => ['points' => 0.5], 'E3' => ['points' => 1.0], 'E9' => ['points' => 0.0]]));
    }

    public function test_mcr_mapping_names_a_source_for_every_variable()
    {
        $rows = McrEvidence::mapping();
        $this->assertSame(['E1', 'E2', 'E3', 'E4', 'E5', 'E6', 'E7', 'E8', 'E9', 'E10'], array_values(array_unique(array_column($rows, 'essential'))));
        $flat = collect($rows)->flatMap(fn ($r) => $r['columns']);
        $this->assertSame('งานป้องกันภัย', $flat->firstWhere('name', 'C8_1_FORMAL_NETWORK_COUNT')['source']);
        // Every variable has a source; the responsible agency (E1) comes from the กลุ่มงาน split (client 2026-11-23).
        $this->assertSame([], $flat->where('source', '–')->all());
        $this->assertSame('การแบ่งกลุ่มงานนำเข้าข้อมูล (ประวัตินำเข้า)', $flat->firstWhere('name', 'กลุ่มงานที่นำเข้าข้อมูลของชุมชน')['source']);
    }

    public function test_pages_receive_trend_radar_and_coverage_data()
    {
        $this->defaultSet();
        $this->seedCommunity('R001', '2025-01-20');
        $this->seedCommunity('R001', '2026-01-20');
        $user = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($user)->get('/community-map')->assertInertia(fn ($page) => $page
            ->where('trend.years', [2568, 2569])
            ->has('trend.communities.R001', 2)
            ->where('communities.0.recoveryItems.R2', 1)
            ->where('cityRecoveryItems.R2', 1));

        $this->actingAs($user)->get('/impact-recovery')->assertInertia(fn ($page) => $page
            ->where('hevcYears', [2568, 2569])
            ->has('hevc.R001.years.2569.multi'));

        $this->actingAs($user)->get('/mcr2030-kpi?tab=mcr')->assertInertia(fn ($page) => $page
            ->has('mcrMapping')
            ->has('city.coverage')
            ->has('communities.0.mcrCoverage'));

        $this->actingAs($user)->get('/risk-factors')->assertInertia(fn ($page) => $page
            ->has('city.hazards.FLOOD.vPeople')
            ->has('communities.0.hazards.FLOOD.vSystem'));
    }
}
