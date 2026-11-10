<?php

namespace Tests\Feature\Scoring;

use App\Models\Community;
use App\Models\ScoringCriteriaSet;
use App\Scoring\Bands;
use App\Scoring\CriteriaSchema;
use App\Scoring\HevcCalculator;
use App\Scoring\RiskEngine;
use App\Scoring\RiskLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiskEngineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Community R001's real values from the client's v4 test file (all 6 sheets), by table.
     *
     * @return array<string, array<string, mixed>>
     */
    private function r001(): array
    {
        return [
            'disaster_records' => [
                'tambon' => 'ตลาด', 'amphoe' => 'เมืองมหาสารคาม', 'province' => 'มหาสารคาม',
                'population' => 1240, 'households' => 460, 'area_km2' => 0.72,
                'e_numerator_count_flood' => 20, 'e_numerator_count_fire' => 20, 'e_numerator_count_storm' => 20,
                'e_numerator_count_heat' => 20, 'e_numerator_count_pm25' => 20,
                'c4_1_volunteer_per_1000' => 10.5, 'c4_2_training_count' => 1,
                'c6_1_response_vehicle_adequacy_score' => 4, 'c6_2_lifesaving_score' => 5, 'c6_3_readiness_score' => 20,
                'c6_4_hazard_equipment_adequacy_score' => 4,
                'c7_1_flood_score' => 4, 'c7_1_fire_score' => 4, 'c7_1_storm_score' => 4, 'c7_2_safe_place_score' => 4, 'c7_3_drill_score' => 4,
                'c8_1_formal_network_count' => 3, 'c8_2_response_hours' => 10, 'c8_3_network_types_score' => 4, 'c8_4_joint_experience_score' => 4,
                'r1_recovery_plan_score' => 4, 'r2_build_back_better_score' => 4, 'r3_socioeconomic_recov_score' => 3,
                'r4_vulner_group_recov_score' => 3, 'r5_learning_sys_improv_score' => 4, 'm_budget_avg_3y' => 850000,
            ],
            'welfare_records' => ['v_elderly_count' => 186, 'v_child_count' => 142, 'v_disabled_count' => 31, 'v_low_income_count' => 96],
            'health_records' => [
                'v_bedridden_count' => 8, 'v_medical_dependent_count' => 5, 'v_assisted_evacuation_count' => 14,
                'v_communication_assistance_count' => 6, 'v_ncd_count' => 86,
            ],
            'education_records' => ['c4_4_school_drr_education_score' => 0],
            'engineering_records' => [
                'v_infra_gap_count_flood' => 1, 'v_infra_gap_count_fire' => 1, 'v_infra_gap_count_storm' => 2,
                'v_infra_gap_count_heat' => 2, 'v_infra_gap_count_pm25' => 2,
                'c5_1_greenblue_score' => 2, 'c5_2_nbs_score' => 2, 'c5_3_maintenance_score' => 2,
            ],
            'public_relations_records' => ['v_warning_access_gap_score' => 1, 'c4_3_risk_comm_score' => 1],
        ];
    }

    private function r001Inputs(): array
    {
        return array_merge(...array_values($this->r001()));
    }

    /**
     * Store a community's records, all dated $date; $overrides is table → column → value, $skip drops tables.
     */
    private function seedCommunity(string $code, string $date, array $overrides = [], array $skip = []): Community
    {
        $community = Community::firstOrCreate(['code' => $code], ['name' => "ชุมชน $code"]);
        foreach ($this->r001() as $table => $values) {
            if (in_array($table, $skip, true)) {
                continue;
            }
            DB::table($table)->insert(array_merge($values, [
                'community_id' => $community->id,
                'assessment_date' => $date,
            ], $overrides[$table] ?? []));
        }

        return $community;
    }

    private function makeSet(int $startYear, string $name = 'ชุดหลัก', array $weights = []): ScoringCriteriaSet
    {
        return ScoringCriteriaSet::create([
            'name' => $name,
            'start_fiscal_year' => $startYear,
            'is_locked' => false,
            'weights' => array_replace_recursive(CriteriaSchema::defaults(), $weights),
        ]);
    }

    public function test_bands_follow_appendix_b()
    {
        $this->assertSame([0, 1, 1, 2, 5, 5], array_map(fn ($v) => Bands::percent($v), [0, 0.1, 20, 20.01, 100, 120]));
        $this->assertSame([0, 1, 2, 3, 4, 5], array_map(fn ($v) => Bands::vulnerablePercent($v), [0, 4.99, 5, 10, 20, 30]));
        $this->assertSame([0, 1, 2, 3, 4, 5], array_map(fn ($v) => Bands::volunteersPer1000($v), [0, 3.9, 4, 6, 8, 10]));
        $this->assertSame([0, 2, 3, 4, 5, 5], array_map(fn ($v) => Bands::trainingCount($v), [0, 1, 2, 3, 4, 9]));
        $this->assertSame([0, 3, 5], array_map(fn ($v) => Bands::networkCount($v), [0, 3, 7]));
        $this->assertSame([5, 4, 3, 2, 1, 0], array_map(fn ($v) => Bands::responseHours($v), [1, 3, 6, 12, 24, 24.5]));
        $this->assertNull(Bands::percent(null));
    }

    public function test_risk_level_uses_the_value_rounded_to_one_decimal()
    {
        $this->assertSame(RiskLevel::Low, RiskLevel::of(24.94));
        $this->assertSame(RiskLevel::Moderate, RiskLevel::of(24.95));
        $this->assertSame(RiskLevel::VeryHigh, RiskLevel::of(74.96));
        $this->assertSame(RiskLevel::High, RiskLevel::of(74.94));
    }

    /**
     * R001 worked by hand from the v4 equations:
     * E = 20/1240 = 1.61% → 1. V_SOCIAL = .3·3+.2·3+.2·1+.3·2 = 2.3; V_HEALTH = .25·1+.3·1+.45·2 = 1.45;
     * V_ASSIST = 1; V_WARNING = 1; V_INFRA_FLOOD = 1 → V_FLOOD = .2·2.3+.15·1.45+.25·1+.15·1+.25·1 = 1.3275.
     * C4 = .25·5+.2·2+.35·1+.2·0 = 2; C5 = 2; C8 = .2·3+.3·2+.2·4+.3·4 = 3.2;
     * C6_FLOOD = .25·4+.25·5+.2·1+.3·4 = 3.65; C7_FLOOD = 4 → C_RAW = .2·2+.2·2+.2·3.65+.25·4+.15·3.2 = 3.01;
     * C_HEVC = 1+.8·3.01 = 3.408. FLOOD_HEVC = 20·(.3846·1+.6154·1.3275)·(1−.16·3.408) = 10.9273.
     */
    public function test_r001_matches_the_hand_calculation()
    {
        $result = (new HevcCalculator($this->r001Inputs(), CriteriaSchema::defaults()))->calculate();
        $flood = $result['hazards']['FLOOD'];

        $this->assertSame(1, $flood['e']);
        $this->assertEqualsWithDelta(1.3275, $flood['v'], 1e-9);
        $this->assertEqualsWithDelta(3.01, $flood['cRaw'], 1e-9);
        $this->assertEqualsWithDelta(3.408, $flood['cHevc'], 1e-9);
        $this->assertEqualsWithDelta(10.9273, $flood['hevc'], 1e-4);
        $this->assertSame('low', $flood['level']);

        // MULTI = .35·FLOOD + .10·FIRE + .15·STORM + .10·HEAT + .30·PM25
        $h = array_map(fn ($r) => $r['hevc'], $result['hazards']);
        $this->assertEqualsWithDelta(.35 * $h['FLOOD'] + .1 * $h['FIRE'] + .15 * $h['STORM'] + .1 * $h['HEAT'] + .3 * $h['PM25'], $result['multi']['hevc'], 1e-9);
        $this->assertEqualsWithDelta(11.5482, $result['multi']['hevc'], 1e-4);
        $this->assertSame('HEAT', $result['multi']['dominant']);

        // RECOVERY_AVG = .25·4+.25·4+.2·3+.15·3+.15·4
        $this->assertEqualsWithDelta(3.65, $result['recovery'], 1e-9);
        $this->assertEqualsWithDelta(3.65, $result['trace']['C6_FLOOD']['value'], 1e-9);
    }

    public function test_an_empty_cell_is_na_and_the_remaining_weights_are_renormalized()
    {
        $inputs = $this->r001Inputs();
        $inputs['c4_4_school_drr_education_score'] = null;

        $result = (new HevcCalculator($inputs, CriteriaSchema::defaults()))->calculate();

        // C4 = (.25·5 + .2·2 + .35·1) / .80
        $this->assertEqualsWithDelta((1.25 + 0.4 + 0.35) / 0.8, $result['groups']['C4'], 1e-9);
    }

    public function test_a_hazard_without_exposure_is_na_and_multi_renormalizes()
    {
        $inputs = $this->r001Inputs();
        $inputs['e_numerator_count_flood'] = null;

        $result = (new HevcCalculator($inputs, CriteriaSchema::defaults()))->calculate();
        $h = array_map(fn ($r) => $r['hevc'], $result['hazards']);

        $this->assertNull($h['FLOOD']);
        $this->assertNull($result['hazards']['FLOOD']['level']);
        $this->assertEqualsWithDelta((.1 * $h['FIRE'] + .15 * $h['STORM'] + .1 * $h['HEAT'] + .3 * $h['PM25']) / .65, $result['multi']['hevc'], 1e-9);
    }

    public function test_engine_uses_the_latest_record_per_sheet_and_can_look_back()
    {
        $this->makeSet(2568);
        $this->seedCommunity('R001', '2025-01-20');
        $this->seedCommunity('R001', '2025-06-01', ['disaster_records' => ['e_numerator_count_flood' => 1240]]); // 100% exposed

        $now = (new RiskEngine)->run()['communities'][0];
        $this->assertSame(5, $now['hazards']['FLOOD']['e']);
        $this->assertSame('2025-06-01', $now['dataDate']);

        $before = (new RiskEngine)->run('2025-03-01')['communities'][0];
        $this->assertSame(1, $before['hazards']['FLOOD']['e']);
        $this->assertSame('2025-01-20', $before['dataDate']);
    }

    public function test_a_community_missing_a_sheet_is_not_scored()
    {
        $this->makeSet(2568);
        $this->seedCommunity('R001', '2025-01-20', skip: ['education_records']);

        $c = (new RiskEngine)->run()['communities'][0];

        $this->assertSame('missing', $c['status']);
        $this->assertSame(['กองการศึกษา'], $c['missingSheets']);
        $this->assertNull($c['multi']);
    }

    public function test_criteria_set_follows_the_fiscal_year_of_the_newest_record_and_can_be_overridden()
    {
        $old = $this->makeSet(2568, 'เดิม');
        $new = $this->makeSet(2569, 'ใหม่', ['hazard_c' => ['ALL' => ['C' => 0.10]]]);
        $this->seedCommunity('R001', '2025-01-20'); // FY 2568
        $this->seedCommunity('R002', '2025-01-20', ['welfare_records' => ['assessment_date' => '2025-10-15']]); // newest FY 2569

        $result = (new RiskEngine)->run();
        $this->assertSame($old->id, $result['communities'][0]['criteriaSet']['id']);
        $this->assertSame($new->id, $result['communities'][1]['criteriaSet']['id']);
        $this->assertGreaterThan($result['communities'][0]['multi']['hevc'], $result['communities'][1]['multi']['hevc']);

        $whatIf = (new RiskEngine)->run(override: $old);
        $this->assertSame($old->id, $whatIf['communities'][1]['criteriaSet']['id']);
        $this->assertEqualsWithDelta($whatIf['communities'][0]['multi']['hevc'], $whatIf['communities'][1]['multi']['hevc'], 1e-9);
    }

    public function test_city_level_is_population_weighted_over_scored_communities()
    {
        $this->makeSet(2568);
        $this->seedCommunity('R001', '2025-01-20'); // pop 1240
        $this->seedCommunity('R002', '2025-01-20', [
            'disaster_records' => ['population' => 3720, 'e_numerator_count_flood' => 3720, 'e_numerator_count_fire' => 3720,
                'e_numerator_count_storm' => 3720, 'e_numerator_count_heat' => 3720, 'e_numerator_count_pm25' => 3720],
        ]);
        $this->seedCommunity('R003', '2025-01-20', skip: ['welfare_records']); // missing

        $result = (new RiskEngine)->run();
        [$a, $b] = $result['communities'];

        $this->assertEqualsWithDelta(($a['multi']['hevc'] * 1240 + $b['multi']['hevc'] * 3720) / 4960, $result['city']['multi']['value'], 1e-9);
        $this->assertSame(2, $result['city']['multi']['communities']);
        $this->assertSame(2, $result['city']['scoredCommunities']);
        $this->assertSame(['R003'], array_column($result['city']['missing'], 'code'));
        $this->assertSame(3, $result['city']['totalCommunities']);
        $this->assertSame(1240 + 3720, $result['city']['counts']['population']);
        $this->assertEqualsWithDelta(
            ($a['groups']['C4'] * 1240 + $b['groups']['C4'] * 3720) / 4960,
            $result['city']['groups']['C4'],
            1e-9,
        );
        $this->assertNull($result['city']['multi']['delta']); // one round only
    }

    public function test_previous_round_is_the_day_before_the_newest_data_and_city_delta_compares_like_with_like()
    {
        $this->makeSet(2568);
        // R001: a new welfare round on 2025-06-01 (elderly 15% → 40%); every other sheet stays the same.
        $this->seedCommunity('R001', '2025-01-20');
        DB::table('welfare_records')->insert(array_merge($this->r001()['welfare_records'], [
            'community_id' => Community::where('code', 'R001')->value('id'),
            'assessment_date' => '2025-06-01',
            'v_elderly_count' => 496,
        ]));
        // R002: one round only, so no previous.
        $this->seedCommunity('R002', '2025-01-20');

        $result = (new RiskEngine)->run();
        [$a, $b] = $result['communities'];

        $this->assertSame('2025-01-20', $a['previous']['dataDate']);
        $this->assertGreaterThan($a['previous']['multi']['hevc'], $a['multi']['hevc']);
        $this->assertNull($b['previous']);

        // City Δ only uses R001 (the one with both rounds).
        $this->assertSame(1, $result['city']['multi']['deltaCommunities']);
        $this->assertEqualsWithDelta($a['multi']['hevc'] - $a['previous']['multi']['hevc'], $result['city']['multi']['delta'], 1e-9);
        $this->assertSame('2025-01-20', $result['city']['previousDataDate']);
    }

    public function test_fiscal_years_with_data()
    {
        $this->seedCommunity('R001', '2025-01-20');
        $this->seedCommunity('R002', '2025-10-01');

        $this->assertSame([2568, 2569], RiskEngine::fiscalYears());
        $this->assertSame('2025-09-30', RiskEngine::fiscalYearEnd(2568));
    }
}
