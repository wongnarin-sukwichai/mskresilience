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

class RiskOverviewTest extends TestCase
{
    use RefreshDatabase;

    /** Minimal but complete records in all 6 sheets for one community. */
    private function seedCommunity(string $code, string $date, int $population = 1000): void
    {
        $id = Community::create(['code' => $code, 'name' => "ชุมชน $code"])->id;
        $base = ['community_id' => $id, 'assessment_date' => $date];
        DB::table('disaster_records')->insert($base + ['population' => $population, 'e_numerator_count_flood' => 100, 'c7_2_safe_place_score' => 3]);
        DB::table('welfare_records')->insert($base + ['v_elderly_count' => 150]);
        DB::table('health_records')->insert($base + ['v_ncd_count' => 80]);
        DB::table('education_records')->insert($base + ['c4_4_school_drr_education_score' => 2]);
        DB::table('engineering_records')->insert($base + ['v_infra_gap_count_flood' => 3]);
        DB::table('public_relations_records')->insert($base + ['v_warning_access_gap_score' => 2]);
    }

    public function test_every_role_can_view_it()
    {
        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/risk-overview')->assertOk();
        }
    }

    public function test_it_shows_an_empty_state_without_data()
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))->get('/risk-overview')->assertInertia(fn ($page) => $page
            ->component('admin/RiskOverview')
            ->where('fiscalYears', [])
            ->where('city', null));
    }

    public function test_it_sends_city_values_and_filters_by_fiscal_year()
    {
        ScoringCriteriaSet::create(['name' => 'ชุด', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()]);
        $this->seedCommunity('R001', '2025-01-20');
        $this->seedCommunity('R002', '2025-11-01'); // FY 2569
        $user = User::factory()->create(['role' => UserRole::Member]);

        $this->actingAs($user)->get('/risk-overview')->assertInertia(fn ($page) => $page
            ->where('fiscalYears', [2568, 2569])
            ->where('year', 2569)
            ->where('city.scoredCommunities', 2)
            ->where('city.multi.dominant', 'FLOOD')
            ->has('communities', 2)
            ->missing('communities.0.trace'));

        // FY 2568 = data as of 2025-09-30, so R002 has no records yet and is "missing".
        $this->actingAs($user)->get('/risk-overview?year=2568')->assertInertia(fn ($page) => $page
            ->where('year', 2568)
            ->where('city.scoredCommunities', 1)
            ->where('city.missing.0.code', 'R002'));
    }

    public function test_only_admins_get_the_source_files_behind_the_numbers()
    {
        ScoringCriteriaSet::create(['name' => 'ชุด', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()]);
        $this->seedCommunity('R001', '2025-01-20');
        $this->seedCommunity('R002', '2025-01-20');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $batch = DB::table('import_batches')->insertGetId([
            'work_group' => 'welfare', 'user_id' => $admin->id, 'original_filename' => 'สวัสดิการ.xlsx', 'source' => 'file', 'status' => 'active',
            'row_count' => 2, 'replaced_count' => 0, 'community_count' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('welfare_records')->update(['import_batch_id' => $batch]);

        $this->actingAs($admin)->get('/risk-overview')->assertInertia(fn ($page) => $page
            ->where('calcSources.welfare.label', 'งานสวัสดิการสังคม')
            ->where('calcSources.welfare.batches.0.id', $batch)
            ->where('calcSources.welfare.batches.0.file', 'สวัสดิการ.xlsx')
            ->where('calcSources.welfare.batches.0.communities', 2)
            ->where('calcSources.disaster.batches', [])
            ->etc());

        foreach ([UserRole::Staff, UserRole::Member] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/risk-overview')
                ->assertInertia(fn ($page) => $page->where('calcSources', null)->etc());
        }
    }
}
