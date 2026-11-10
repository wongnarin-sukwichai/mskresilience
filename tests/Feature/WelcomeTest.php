<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\ScoringCriteriaSet;
use App\Scoring\CriteriaSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WelcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_data_the_homepage_has_no_risk()
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome')->where('risk', null));
    }

    public function test_guests_see_each_communitys_live_risk_level_but_no_raw_data()
    {
        ScoringCriteriaSet::create(['name' => 'ชุด', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()]);
        $id = Community::create(['code' => 'R001', 'name' => 'ศรีสวัสดิ์ 2'])->id;
        Community::create(['code' => 'R002', 'name' => 'ตักศิลา 2']); // no data → missing
        $base = ['community_id' => $id, 'assessment_date' => '2025-01-20'];
        DB::table('disaster_records')->insert($base + ['population' => 1240, 'e_numerator_count_flood' => 20, 'c7_2_safe_place_score' => 4]);
        DB::table('welfare_records')->insert($base + ['v_elderly_count' => 186]);
        DB::table('health_records')->insert($base + ['v_ncd_count' => 86]);
        DB::table('education_records')->insert($base + ['c4_4_school_drr_education_score' => 0]);
        DB::table('engineering_records')->insert($base + ['v_infra_gap_count_flood' => 1]);
        DB::table('public_relations_records')->insert($base + ['v_warning_access_gap_score' => 1]);

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('risk.dataDate', '2025-01-20')
            ->where('risk.city.communities', 1)
            ->where('risk.communities.0.code', 'R001')
            ->where('risk.communities.0.status', 'ok')
            ->where('risk.communities.0.multi.level', 'low')
            ->has('risk.communities.0.hazards.FLOOD', fn (Assert $h) => $h->hasAll(['hevc', 'level']))
            ->where('risk.communities.1.status', 'missing')
            // Public page: only scores and levels, never the inputs behind them.
            ->has('risk.communities.0', fn (Assert $c) => $c->hasAll(['code', 'status', 'dataDate', 'multi', 'hazards']))
        );
    }
}
