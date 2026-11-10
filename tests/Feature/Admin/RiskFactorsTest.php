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

class RiskFactorsTest extends TestCase
{
    use RefreshDatabase;

    private function seedCommunity(string $code, string $date, int $exposed): void
    {
        $id = Community::firstOrCreate(['code' => $code], ['name' => "ชุมชน $code"])->id;
        $base = ['community_id' => $id, 'assessment_date' => $date];
        DB::table('disaster_records')->insert($base + ['population' => 1000, 'e_numerator_count_flood' => $exposed, 'c7_2_safe_place_score' => 3]);
        DB::table('welfare_records')->insert($base + ['v_elderly_count' => 150]);
        DB::table('health_records')->insert($base + ['v_ncd_count' => 80]);
        DB::table('education_records')->insert($base + ['c4_4_school_drr_education_score' => 2]);
        DB::table('engineering_records')->insert($base + ['v_infra_gap_count_flood' => 3]);
        DB::table('public_relations_records')->insert($base + ['v_warning_access_gap_score' => 2]);
    }

    public function test_every_role_can_view_it()
    {
        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/risk-factors')->assertOk();
        }
    }

    public function test_it_sends_city_exposure_group_deltas_and_the_weights_in_use()
    {
        $set = ScoringCriteriaSet::create(['name' => 'ชุด', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()]);
        $this->seedCommunity('R001', '2025-01-20', 100);
        $this->seedCommunity('R002', '2025-01-20', 300);

        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
            ->get('/risk-factors?community=R002&hazard=FLOOD')
            ->assertInertia(fn ($page) => $page
                ->component('admin/RiskFactors')
                ->where('initialCommunity', 'R002')
                ->where('city.exposure.FLOOD.count', 400)
                ->where('city.exposure.FLOOD.percent', 20) // 400 of 2000
                ->where('city.exposure.FLOOD.score', 1)
                ->has('city.groupDeltas.C4')
                ->has('city.hazards.FLOOD.vDelta')
                ->where("weights.{$set->id}.v_hazard.FLOOD.V_INFRA", 0.25)
                ->missing("weights.{$set->id}.recovery")
                ->has('communities', 2)
                ->where('communities.1.hazards.FLOOD.e', 2)); // 30% → 2
    }
}
