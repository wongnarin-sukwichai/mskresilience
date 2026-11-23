<?php

namespace Tests\Feature\Admin;

use App\Ai\SummaryWriter;
use App\Enums\UserRole;
use App\Models\Community;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Scoring\CriteriaSchema;
use App\Scoring\ExecutiveSummary;
use App\Scoring\PolicyFlags;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * สรุปสำหรับผู้บริหาร (TOR 4.1.1(3)): scatter, MCR coverage, Policy Flags (admin thresholds), Executive Summary
 * (rule-based; AI optional with a number check) and before–after investment.
 */
class ExecutiveTest extends TestCase
{
    use RefreshDatabase;

    /** One community's 6 sheets; $exposed drives its risk, $budget its 3-year budget. */
    private function seedCommunity(string $code, string $date, int $exposed, int $budget, int $recovery = 4): void
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
                'r1_recovery_plan_score' => $recovery, 'r2_build_back_better_score' => $recovery, 'r3_socioeconomic_recov_score' => $recovery,
                'r4_vulner_group_recov_score' => $recovery, 'r5_learning_sys_improv_score' => $recovery, 'm_budget_avg_3y' => $budget,
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
        // R001 risky + poor, rising; R002 safe + rich; R003/R004 in between.
        $this->seedCommunity('R001', '2025-01-20', exposed: 500, budget: 200000, recovery: 1);
        $this->seedCommunity('R001', '2026-01-20', exposed: 950, budget: 200000, recovery: 1);
        $this->seedCommunity('R002', '2025-01-20', exposed: 50, budget: 2000000);
        $this->seedCommunity('R002', '2026-01-20', exposed: 50, budget: 2000000);
        $this->seedCommunity('R003', '2026-01-20', exposed: 300, budget: 900000);
        $this->seedCommunity('R004', '2026-01-20', exposed: 200, budget: 800000);
    }

    public function test_every_role_sees_the_page_with_flags_scatter_and_rule_summary()
    {
        $this->seedCity();
        $member = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($member)->get('/executive')->assertInertia(fn ($page) => $page
            ->component('admin/Executive')
            ->where('empty', false)
            ->has('scatter', 4)
            ->where('flags.thresholds.mode', 'relative')
            ->where('flags.flags.0.type', 'high_risk_low_budget')
            ->where('flags.flags.0.code', 'R001')
            ->where('summary.mode', 'rule')
            ->where('summary.facts', null) // only admins see what would be sent to AI
            ->where('summary.usage', null)
            ->has('summary.rules', 6));
    }

    public function test_relative_cut_and_fixed_thresholds()
    {
        $this->assertSame(90.0, PolicyFlags::cut([10, 90, 50, 70], 25, top: true));
        $this->assertSame(10.0, PolicyFlags::cut([10, 90, 50, 70], 25, top: false));
        $this->assertSame(70.0, PolicyFlags::cut([10, 90, 50, 70], 50, top: true));
        $this->assertNull(PolicyFlags::cut([null], 25, top: true));

        $this->seedCity();
        Settings::set('flag_mode', 'fixed');
        Settings::set('flag_hevc', 99);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->get('/executive')->assertInertia(fn ($page) => $page
            ->where('flags.thresholds.hevc', 99)
            ->where('flags.counts.high_risk_low_budget', 0)
            ->where('flags.counts.rising', 1)); // rising uses its own threshold, not the HEVC cut
    }

    public function test_rule_summary_reports_the_city_change_and_flags()
    {
        $this->seedCity();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rules = null;
        $this->actingAs($admin)->get('/executive')->assertInertia(function ($page) use (&$rules) {
            $rules = $page->toArray()['props']['summary']['rules'];
        });
        $this->assertStringStartsWith('ปีงบประมาณ 2569 ความเสี่ยงรวมของเมืองอยู่ที่', $rules[0]);
        $this->assertStringContainsString('จากปีงบ 2568', $rules[0]);
        $this->assertStringContainsString('เสี่ยงสูง แต่งบประมาณต่ำ 1 ชุมชน (ชุมชน R001)', implode(' ', $rules));
        $this->assertSame(['ยังไม่มีข้อมูลเพียงพอสำหรับสรุปภาพรวม'], ExecutiveSummary::rules(['city_hevc' => null]));
    }

    public function test_ai_text_with_a_number_not_in_the_facts_is_not_used()
    {
        $facts = ['city_hevc' => 22.9, 'top_risk_communities' => [['name' => 'ธัญญา 2', 'hevc' => 75.3]], 'count' => 1200];
        $this->assertSame([], SummaryWriter::unknownNumbers('ความเสี่ยง 22.9 ชุมชนธัญญา 2 ได้ 75.3 จำนวน 1,200', $facts));
        $this->assertSame(['23', '80'], SummaryWriter::unknownNumbers('ความเสี่ยงราว 23 และ 80 คะแนน', $facts));
    }

    public function test_ai_generation_needs_ai_mode_a_key_and_an_admin()
    {
        $this->seedCity();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]))->post('/executive/ai-summary')->assertForbidden();

        $this->actingAs($admin)->post('/executive/ai-summary')->assertSessionHas('error');
        Settings::set('summary_mode', 'ai');
        config(['ai.api_key' => null]);
        $this->actingAs($admin)->post('/executive/ai-summary')->assertSessionHas('error', fn ($m) => str_contains($m, 'ANTHROPIC_API_KEY'));
        $this->actingAs($admin)->get('/executive')->assertInertia(fn ($page) => $page
            ->where('summary.mode', 'ai')
            ->where('summary.aiConfigured', false)
            ->where('summary.ai', null)
            ->has('summary.facts.city_hevc'));
    }

    public function test_before_after_shows_an_f4_event_between_two_rounds()
    {
        $this->seedCity();
        $r001 = Community::where('code', 'R001')->value('id');
        DB::table('disaster_events')->insert([
            'event_id' => 'EV0001', 'community_id' => $r001, 'event_start' => '2025-06-01', 'primary_hazard' => 'FLOOD',
            'multi_hazard_flag' => false, 'f4_event_risk_reduction_budget_thb' => 500000,
        ]);
        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))->get('/executive')->assertInertia(fn ($page) => $page
            ->where('investments.0.type', 'f4')
            ->where('investments.0.code', 'R001')
            ->where('investments.0.before.year', 2568)
            ->where('investments.0.after.0.year', 2569));
    }
}
