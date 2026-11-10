<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Scoring\CriteriaSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImpactRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_can_view_it()
    {
        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/impact-recovery')->assertOk();
        }
    }

    public function test_events_carry_readiness_from_before_the_event_and_f1_as_the_sum_of_its_parts()
    {
        ScoringCriteriaSet::create(['name' => 'ชุด', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()]);
        $id = Community::create(['code' => 'R001', 'name' => 'ศรีสวัสดิ์ 2'])->id;
        $readiness = fn (string $date, int $score) => DB::table('disaster_records')->insert([
            'community_id' => $id, 'assessment_date' => $date, 'm_budget_avg_3y' => 850000,
            'r1_recovery_plan_score' => $score, 'r2_build_back_better_score' => $score, 'r3_socioeconomic_recov_score' => $score,
            'r4_vulner_group_recov_score' => $score, 'r5_learning_sys_improv_score' => $score,
        ]);
        $readiness('2025-01-10', 2); // before the event
        $readiness('2025-03-01', 5); // after the event: must not be used
        DB::table('disaster_events')->insert([
            'event_id' => 'EV0001', 'community_id' => $id, 'event_start' => '2025-02-01', 'primary_hazard' => 'FLOOD',
            'related_hazards' => 'FLOOD,STORM', 'multi_hazard_flag' => true,
            'r1_observed' => 3, 'r2_observed' => 3, 'r3_observed' => 3, 'r4_observed' => 3, 'r5_observed' => 3,
            'f1_event_total_budget_thb' => 999, // disagrees with the parts
            'f2_event_response_cost_thb' => 100, 'f3_event_recovery_cost_thb' => 200, 'f4_event_risk_reduction_budget_thb' => 300,
        ]);

        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
            ->get('/impact-recovery')
            ->assertInertia(fn ($page) => $page
                ->component('admin/ImpactRecovery')
                ->where('events.0.id', 'EV0001')
                ->where('events.0.fiscalYear', 2568)
                ->where('events.0.related', ['FLOOD', 'STORM'])
                ->where('events.0.readiness.date', '2025-01-10')
                ->where('events.0.readiness.avg', 2)
                ->where('events.0.observed.avg', 3)
                ->where('events.0.finance.F1', 600)
                ->where('events.0.finance.importedF1Mismatch', true)
                ->where('budgets.R001', 850000));
    }
}
