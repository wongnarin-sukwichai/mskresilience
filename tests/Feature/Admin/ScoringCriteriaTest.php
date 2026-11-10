<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Models\WelfareRecord;
use App\Scoring\CriteriaSchema;
use App\Scoring\CriteriaSets;
use Database\Seeders\ScoringCriteriaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoringCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin]);
    }

    private function makeSet(array $attributes = []): ScoringCriteriaSet
    {
        return ScoringCriteriaSet::create(array_merge([
            'name' => 'ชุดทดสอบ',
            'start_fiscal_year' => 2568,
            'is_locked' => false,
            'weights' => CriteriaSchema::defaults(),
        ], $attributes));
    }

    public function test_client_default_weights_are_all_valid()
    {
        $this->assertSame([], CriteriaSchema::validate(CriteriaSchema::defaults()));
    }

    /** A welfare record on this date, so its fiscal year has data. */
    private function dataOn(string $date): void
    {
        $community = Community::firstOrCreate(['code' => 'R001'], ['name' => 'ศรีสวัสดิ์ 2']);
        WelfareRecord::create(['community_id' => $community->id, 'assessment_date' => $date]);
    }

    public function test_seeder_creates_one_unlocked_set_once()
    {
        $this->seed(ScoringCriteriaSeeder::class);
        $this->seed(ScoringCriteriaSeeder::class);

        $this->assertSame(1, ScoringCriteriaSet::count());
        $set = ScoringCriteriaSet::first();
        $this->assertSame(2568, $set->start_fiscal_year);
        $this->assertFalse($set->is_locked);
        $this->assertSame(0.35, $set->weights['multi_hevc']['ALL']['FLOOD_HEVC']);
        $this->assertSame(0.3846, $set->weights['hazard_ev']['ALL']['E']);
        $this->assertArrayNotHasKey('C5', $set->weights['c_hazard']['FIRE']);
    }

    public function test_only_admin_can_access()
    {
        foreach ([UserRole::Staff, UserRole::Member] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/scoring-criteria')->assertStatus(403);
        }

        $this->actingAs($this->admin())->get('/scoring-criteria')->assertStatus(200);
    }

    public function test_index_sends_sets_and_schema()
    {
        $this->seed(ScoringCriteriaSeeder::class);

        $this->actingAs($this->admin())->get('/scoring-criteria')->assertInertia(fn ($page) => $page
            ->component('admin/ScoringCriteria')
            ->has('sets', 1)
            ->has('schema', 8));
    }

    public function test_admin_can_update_weights()
    {
        $set = $this->makeSet();
        $weights = CriteriaSchema::defaults();
        $weights['multi_hevc']['ALL']['FLOOD_HEVC'] = 0.30;
        $weights['multi_hevc']['ALL']['FIRE_HEVC'] = 0.15;
        $weights['hazard_c']['ALL']['C'] = 0.25; // above 0.20 is allowed (owner decision) — only a UI warning

        $this->actingAs($this->admin())
            ->put("/scoring-criteria/{$set->id}", ['name' => 'ชุดใหม่ 2569–2575', 'start_fiscal_year' => 2569, 'weights' => $weights])
            ->assertSessionHasNoErrors();

        $set->refresh();
        $this->assertSame('ชุดใหม่ 2569–2575', $set->name);
        $this->assertSame(2569, $set->start_fiscal_year);
        $this->assertSame(0.3, $set->weights['multi_hevc']['ALL']['FLOOD_HEVC']);
        $this->assertSame(0.25, $set->weights['hazard_c']['ALL']['C']);
    }

    public function test_row_must_sum_to_one()
    {
        $set = $this->makeSet();
        $weights = CriteriaSchema::defaults();
        $weights['v_hazard']['FLOOD']['V_SOCIAL'] = 0.30; // row now sums to 1.10

        $this->actingAs($this->admin())
            ->put("/scoring-criteria/{$set->id}", ['name' => $set->name, 'start_fiscal_year' => 2568, 'weights' => $weights])
            ->assertSessionHasErrors('weights.v_hazard.FLOOD');

        $this->assertSame(0.2, $set->fresh()->weights['v_hazard']['FLOOD']['V_SOCIAL']);
    }

    public function test_weight_range_and_decimals_are_enforced()
    {
        $set = $this->makeSet();

        foreach ([1.5, -0.1, 0.12345, 'abc'] as $bad) {
            $weights = CriteriaSchema::defaults();
            $weights['hazard_c']['ALL']['C'] = $bad;

            $this->actingAs($this->admin())
                ->put("/scoring-criteria/{$set->id}", ['name' => $set->name, 'start_fiscal_year' => 2568, 'weights' => $weights])
                ->assertSessionHasErrors('weights.hazard_c.ALL.C');
        }
    }

    public function test_cells_outside_the_schema_are_dropped_and_fixed_rows_forced()
    {
        $set = $this->makeSet();
        $weights = CriteriaSchema::defaults();
        $weights['c_hazard']['FIRE']['C5'] = 0.5; // not in FIRE's formula
        $weights['v_warning']['ALL']['V_WARNING_ACCESS_GAP_SCORE'] = 0.3; // fixed at 1

        $this->actingAs($this->admin())
            ->put("/scoring-criteria/{$set->id}", ['name' => $set->name, 'start_fiscal_year' => 2568, 'weights' => $weights])
            ->assertSessionHasNoErrors();

        $set->refresh();
        $this->assertArrayNotHasKey('C5', $set->weights['c_hazard']['FIRE']);
        $this->assertSame(1.0, (float) $set->weights['v_warning']['ALL']['V_WARNING_ACCESS_GAP_SCORE']);
    }

    public function test_locked_set_cannot_be_updated_until_unlocked()
    {
        $set = $this->makeSet(['is_locked' => true]);
        $admin = $this->admin();
        $payload = ['name' => 'เปลี่ยนชื่อ', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()];

        $this->actingAs($admin)->put("/scoring-criteria/{$set->id}", $payload)->assertSessionHas('error');
        $this->assertSame('ชุดทดสอบ', $set->fresh()->name);

        $this->actingAs($admin)->post("/scoring-criteria/{$set->id}/unlock");
        $this->actingAs($admin)->put("/scoring-criteria/{$set->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('เปลี่ยนชื่อ', $set->fresh()->name);

        $this->actingAs($admin)->post("/scoring-criteria/{$set->id}/lock");
        $this->assertTrue($set->fresh()->is_locked);
    }

    public function test_fiscal_year_starts_in_october()
    {
        $this->assertSame(2568, CriteriaSets::fiscalYear('2024-10-01'));
        $this->assertSame(2568, CriteriaSets::fiscalYear('2025-09-30'));
        $this->assertSame(2569, CriteriaSets::fiscalYear('2025-10-01'));
    }

    public function test_a_year_uses_the_latest_set_started_on_or_before_it_and_older_years_use_the_earliest()
    {
        $a = $this->makeSet(['name' => 'A', 'start_fiscal_year' => 2568]);
        $b = $this->makeSet(['name' => 'B', 'start_fiscal_year' => 2570]);
        $sets = ScoringCriteriaSet::all();

        $this->assertSame($a->id, CriteriaSets::resolve(2560, $sets)->id);
        $this->assertSame($a->id, CriteriaSets::resolve(2569, $sets)->id);
        $this->assertSame($b->id, CriteriaSets::resolve(2570, $sets)->id);
        $this->assertSame($b->id, CriteriaSets::resolve(2580, $sets)->id);
    }

    public function test_a_set_used_by_data_is_read_only()
    {
        $set = $this->makeSet(['start_fiscal_year' => 2568]);
        $this->dataOn('2025-01-20'); // ปีงบ 2568
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put("/scoring-criteria/{$set->id}", ['name' => 'เปลี่ยน', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()])
            ->assertSessionHas('error');
        $this->assertSame('ชุดทดสอบ', $set->fresh()->name);

        $this->actingAs($admin)->post("/scoring-criteria/{$set->id}/lock");
        $this->actingAs($admin)->post("/scoring-criteria/{$set->id}/unlock")->assertSessionHas('error');
        $this->assertTrue($set->fresh()->is_locked);

        $this->makeSet(['name' => 'อื่น', 'start_fiscal_year' => 2570]);
        $this->actingAs($admin)->delete("/scoring-criteria/{$set->id}")->assertSessionHas('error');
        $this->assertModelExists($set);

        $this->actingAs($admin)->get('/scoring-criteria')->assertInertia(fn ($page) => $page
            ->where('sets.0.used_years', [2568])
            ->where('sets.0.end_fiscal_year', 2569)
            ->where('sets.1.used_years', [])
            ->where('sets.1.end_fiscal_year', null)
            ->where('dataYears', [2568]));
    }

    public function test_older_data_uses_the_earliest_set_so_it_is_in_use()
    {
        $set = $this->makeSet(['start_fiscal_year' => 2570]);
        $this->dataOn('2025-01-20'); // ปีงบ 2568, before any set

        $this->assertTrue(CriteriaSets::inUse($set));
    }

    public function test_a_start_year_may_not_move_existing_data_to_another_set()
    {
        $used = $this->makeSet(['name' => 'ใช้แล้ว', 'start_fiscal_year' => 2568]);
        $this->dataOn('2025-01-20'); // 2568
        $this->dataOn('2025-11-01'); // 2569
        $admin = $this->admin();

        // A new set starting 2569 would take 2569's data away from "ใช้แล้ว".
        $this->actingAs($admin)
            ->post('/scoring-criteria', ['name' => 'ใหม่', 'start_fiscal_year' => 2569, 'copy_from' => $used->id])
            ->assertSessionHasErrors('start_fiscal_year');
        // Starting after the data is fine.
        $this->actingAs($admin)
            ->post('/scoring-criteria', ['name' => 'ใหม่', 'start_fiscal_year' => 2570, 'copy_from' => $used->id])
            ->assertSessionHasNoErrors();

        // Nor can an unused set be moved back over data.
        $new = ScoringCriteriaSet::where('name', 'ใหม่')->firstOrFail();
        $this->actingAs($admin)
            ->put("/scoring-criteria/{$new->id}", ['name' => 'ใหม่', 'start_fiscal_year' => 2569, 'weights' => CriteriaSchema::defaults()])
            ->assertSessionHasErrors('start_fiscal_year');
        $this->assertSame(2570, $new->fresh()->start_fiscal_year);
    }

    public function test_start_years_must_be_unique()
    {
        $a = $this->makeSet(['name' => 'A', 'start_fiscal_year' => 2568]);

        $this->actingAs($this->admin())
            ->post('/scoring-criteria', ['name' => 'B', 'start_fiscal_year' => 2568, 'copy_from' => $a->id])
            ->assertSessionHasErrors('start_fiscal_year');
    }

    public function test_the_last_set_cannot_be_deleted()
    {
        $set = $this->makeSet();

        $this->actingAs($this->admin())->delete("/scoring-criteria/{$set->id}")->assertSessionHas('error');
        $this->assertModelExists($set);
    }

    public function test_clone_copies_weights_as_an_unlocked_set()
    {
        $weights = CriteriaSchema::defaults();
        $weights['recovery']['ALL']['R1'] = 0.30;
        $weights['recovery']['ALL']['R2'] = 0.20;
        $source = $this->makeSet(['is_locked' => true, 'weights' => $weights]);

        $this->actingAs($this->admin())
            ->post('/scoring-criteria', ['name' => 'สำเนา', 'start_fiscal_year' => 2570, 'copy_from' => $source->id])
            ->assertSessionHasNoErrors();

        $clone = ScoringCriteriaSet::where('name', 'สำเนา')->firstOrFail();
        $this->assertSame(2570, $clone->start_fiscal_year);
        $this->assertFalse($clone->is_locked);
        $this->assertSame($source->weights, $clone->weights);
    }

    public function test_only_unused_unlocked_sets_can_be_deleted()
    {
        $admin = $this->admin();
        $this->makeSet(['name' => 'อื่น', 'start_fiscal_year' => 2567]);
        $locked = $this->makeSet(['name' => 'ล็อก', 'start_fiscal_year' => 2569, 'is_locked' => true]);
        $free = $this->makeSet(['name' => 'ว่าง', 'start_fiscal_year' => 2570]);

        $this->actingAs($admin)->delete("/scoring-criteria/{$locked->id}")->assertSessionHas('error');
        $this->actingAs($admin)->delete("/scoring-criteria/{$free->id}")->assertSessionHasNoErrors();

        $this->assertModelExists($locked);
        $this->assertModelMissing($free);
    }

    public function test_names_must_be_unique()
    {
        $a = $this->makeSet(['name' => 'A']);
        $this->makeSet(['name' => 'B', 'start_fiscal_year' => 2570]);

        $this->actingAs($this->admin())
            ->put("/scoring-criteria/{$a->id}", ['name' => 'B', 'start_fiscal_year' => 2568, 'weights' => CriteriaSchema::defaults()])
            ->assertSessionHasErrors('name');
    }
}
