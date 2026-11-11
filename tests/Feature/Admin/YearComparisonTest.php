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

class YearComparisonTest extends TestCase
{
    use RefreshDatabase;

    /** Complete records in all 6 sheets for one community on one date. */
    private function seedRecords(string $code, string $date, int $exposedFlood = 100): void
    {
        $id = Community::firstOrCreate(['code' => $code], ['name' => "ชุมชน $code"])->id;
        $base = ['community_id' => $id, 'assessment_date' => $date];
        DB::table('disaster_records')->insert($base + ['population' => 1000, 'e_numerator_count_flood' => $exposedFlood, 'c7_2_safe_place_score' => 3]);
        DB::table('welfare_records')->insert($base + ['v_elderly_count' => 150]);
        DB::table('health_records')->insert($base + ['v_ncd_count' => 80]);
        DB::table('education_records')->insert($base + ['c4_4_school_drr_education_score' => 2]);
        DB::table('engineering_records')->insert($base + ['v_infra_gap_count_flood' => 3]);
        DB::table('public_relations_records')->insert($base + ['v_warning_access_gap_score' => 2]);
    }

    private function member(): User
    {
        return User::factory()->create(['role' => UserRole::Member]);
    }

    public function test_every_role_can_view_it()
    {
        foreach ([UserRole::Admin, UserRole::Staff, UserRole::Member] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/year-comparison')->assertOk();
        }
    }

    public function test_it_needs_two_fiscal_years()
    {
        ScoringCriteriaSet::create(['name' => 'ชุด', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()]);
        $this->seedRecords('R001', '2025-01-20');

        $this->actingAs($this->member())->get('/year-comparison')->assertInertia(fn ($page) => $page
            ->component('admin/YearComparison')
            ->where('fiscalYears', [2568])
            ->where('result', null));
    }

    public function test_it_compares_two_years_and_marks_communities_without_new_data()
    {
        ScoringCriteriaSet::create(['name' => 'ชุด', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()]);
        $this->seedRecords('R001', '2025-01-20', 100);
        $this->seedRecords('R001', '2025-11-01', 400); // FY 2569: more people exposed to floods
        $this->seedRecords('R002', '2025-01-20');       // no import in 2569

        $this->actingAs($this->member())->get('/year-comparison')->assertInertia(fn ($page) => $page
            ->where('yearA', 2568)
            ->where('yearB', 2569)
            ->where('result.a.asOf', '2025-09-30')
            ->where('result.b.latest', true)
            ->where('result.comparable', 1)
            ->where('result.setsDiffer', false)
            ->where('result.communities.0.code', 'R001')
            ->where('result.communities.0.stale', false)
            ->where('result.communities.0.a.fiscalYear', 2568)
            ->where('result.communities.0.b.fiscalYear', 2569)
            ->where('result.communities.0.b.criteriaSet.name', 'ชุด')
            ->where('result.communities.1.stale', true)
            ->where('result.communities.1.b.fiscalYear', 2568)
            ->where('result.hazards.FLOOD.cause', ['driver' => 'E', 'direction' => 'up'])
            ->where('result.hazards.FLOOD.fromData', null)
            ->missing('result.communities.0.a.inputs')
            ->missing('result.communities.0.b.trace'));

        $page = $this->actingAs($this->member())->get('/year-comparison')->viewData('page');
        $this->assertGreaterThan(0, $page['props']['result']['hazards']['FLOOD']['delta']);
    }

    public function test_a_criteria_change_between_the_years_is_split_from_the_data_change()
    {
        $weights = CriteriaSchema::defaults();
        ScoringCriteriaSet::create(['name' => 'เดิม', 'start_fiscal_year' => 2568, 'weights' => $weights]);
        $weights['hazard_c']['ALL']['C'] = 0.10;
        ScoringCriteriaSet::create(['name' => 'ใหม่', 'start_fiscal_year' => 2569, 'weights' => $weights]);
        $this->seedRecords('R001', '2025-01-20', 100);
        $this->seedRecords('R001', '2025-11-01', 100); // same data, new set

        $this->actingAs($this->member())->get('/year-comparison?a=2568&b=2569')->assertInertia(fn ($page) => $page
            ->where('result.setsDiffer', true)
            ->where('result.communities.0.a.criteriaSet.name', 'เดิม')
            ->where('result.communities.0.b.criteriaSet.name', 'ใหม่'));

        $hazard = $this->actingAs($this->member())->get('/year-comparison?a=2568&b=2569')->viewData('page')['props']['result']['hazards']['FLOOD'];
        // The data didn't change, so the whole Δ comes from the criteria.
        $this->assertEqualsWithDelta(0.0, $hazard['fromData'], 1e-9);
        $this->assertEqualsWithDelta($hazard['delta'], $hazard['fromCriteria'], 1e-9);
        $this->assertNotEquals(0.0, $hazard['delta']);
    }
}
