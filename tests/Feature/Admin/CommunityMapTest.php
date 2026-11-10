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

class CommunityMapTest extends TestCase
{
    use RefreshDatabase;

    private function seedCommunity(string $code, string $date, bool $complete = true): void
    {
        $id = Community::create(['code' => $code, 'name' => "ชุมชน $code"])->id;
        $base = ['community_id' => $id, 'assessment_date' => $date];
        DB::table('disaster_records')->insert($base + [
            'population' => 1000, 'households' => 400, 'area_km2' => 0.75, 'e_numerator_count_flood' => 100,
            'c7_2_safe_place_score' => 3, 'c8_2_response_hours' => 2.5,
        ]);
        DB::table('welfare_records')->insert($base + ['v_elderly_count' => 150]);
        DB::table('health_records')->insert($base + ['v_ncd_count' => 80]);
        DB::table('education_records')->insert($base + ['c4_4_school_drr_education_score' => 2]);
        DB::table('engineering_records')->insert($base + ['v_infra_gap_count_flood' => 3]);
        if ($complete) {
            DB::table('public_relations_records')->insert($base + ['v_warning_access_gap_score' => 2]);
        }
    }

    public function test_every_role_can_view_it()
    {
        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/community-map')->assertOk();
        }
    }

    public function test_it_sends_each_community_with_panel_data_and_the_preselection()
    {
        ScoringCriteriaSet::create(['name' => 'ชุด', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()]);
        $this->seedCommunity('R001', '2025-01-20');
        $this->seedCommunity('R002', '2025-01-20', complete: false);

        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
            ->get('/community-map?community=R001&hazard=FLOOD')
            ->assertInertia(fn ($page) => $page
                ->component('admin/CommunityMap')
                ->where('initialCommunity', 'R001')
                ->where('initialHazard', 'FLOOD')
                ->where('cityDominant', 'FLOOD')
                ->where('communities.0.code', 'R001')
                ->where('communities.0.status', 'ok')
                ->where('communities.0.households', 400)
                ->where('communities.0.areaKm2', 0.75)
                ->where('communities.0.responseHours', 2.5)
                ->has('communities.0.hazards.FLOOD.hevc')
                ->missing('communities.0.trace')
                ->where('communities.1.status', 'missing')
                ->where('communities.1.missingSheets', ['ประชาสัมพันธ์']));
    }
}
