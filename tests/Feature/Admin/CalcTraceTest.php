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

class CalcTraceTest extends TestCase
{
    use RefreshDatabase;

    private function seedR001(): void
    {
        ScoringCriteriaSet::create(['name' => 'ชุด', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()]);
        $id = Community::create(['code' => 'R001', 'name' => 'ศรีสวัสดิ์ 2'])->id;
        $base = ['community_id' => $id, 'assessment_date' => '2025-01-20'];
        DB::table('disaster_records')->insert($base + ['population' => 1240, 'e_numerator_count_flood' => 20, 'c7_2_safe_place_score' => 4]);
        DB::table('welfare_records')->insert($base + ['v_elderly_count' => 186]);
        DB::table('health_records')->insert($base + ['v_ncd_count' => 86]);
        DB::table('education_records')->insert($base + ['c4_4_school_drr_education_score' => 0]);
        DB::table('engineering_records')->insert($base + ['v_infra_gap_count_flood' => 1]);
        DB::table('public_relations_records')->insert($base + ['v_warning_access_gap_score' => 1]);
    }

    public function test_only_admins_can_see_the_trace()
    {
        $this->seedR001();
        foreach ([UserRole::Staff, UserRole::Member] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/calc-trace/R001')->assertForbidden();
        }
    }

    public function test_it_returns_the_engine_trace_for_one_community()
    {
        $this->seedR001();

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->getJson('/calc-trace/R001')
            ->assertOk()
            ->assertJsonPath('code', 'R001')
            ->assertJsonPath('constants.wE', 0.3846)
            ->assertJsonPath('trace.S_ELDERLY.raw', 186)
            ->assertJsonPath('trace.S_ELDERLY.value', 3) // 15% → 3
            ->assertJsonPath('trace.E_FLOOD.value', 1)
            ->assertJsonStructure(['trace' => ['V_FLOOD' => ['value', 'terms'], 'FLOOD_HEVC', 'MULTI_HEVC']]);
    }

    public function test_unknown_community_is_404()
    {
        $this->seedR001();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->getJson('/calc-trace/R999')->assertNotFound();
    }
}
