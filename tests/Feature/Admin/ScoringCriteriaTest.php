<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\ScoringCriteriaSet;
use App\Models\User;
use App\Scoring\CriteriaSchema;
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
            'is_active' => false,
            'is_locked' => false,
            'weights' => CriteriaSchema::defaults(),
        ], $attributes));
    }

    public function test_client_default_weights_are_all_valid()
    {
        $this->assertSame([], CriteriaSchema::validate(CriteriaSchema::defaults()));
    }

    public function test_seeder_creates_one_active_unlocked_set_once()
    {
        $this->seed(ScoringCriteriaSeeder::class);
        $this->seed(ScoringCriteriaSeeder::class);

        $this->assertSame(1, ScoringCriteriaSet::count());
        $set = ScoringCriteriaSet::first();
        $this->assertTrue($set->is_active);
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
            ->put("/scoring-criteria/{$set->id}", ['name' => 'ชุดใหม่ 2569–2575', 'weights' => $weights])
            ->assertSessionHasNoErrors();

        $set->refresh();
        $this->assertSame('ชุดใหม่ 2569–2575', $set->name);
        $this->assertSame(0.3, $set->weights['multi_hevc']['ALL']['FLOOD_HEVC']);
        $this->assertSame(0.25, $set->weights['hazard_c']['ALL']['C']);
    }

    public function test_row_must_sum_to_one()
    {
        $set = $this->makeSet();
        $weights = CriteriaSchema::defaults();
        $weights['v_hazard']['FLOOD']['V_SOCIAL'] = 0.30; // row now sums to 1.10

        $this->actingAs($this->admin())
            ->put("/scoring-criteria/{$set->id}", ['name' => $set->name, 'weights' => $weights])
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
                ->put("/scoring-criteria/{$set->id}", ['name' => $set->name, 'weights' => $weights])
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
            ->put("/scoring-criteria/{$set->id}", ['name' => $set->name, 'weights' => $weights])
            ->assertSessionHasNoErrors();

        $set->refresh();
        $this->assertArrayNotHasKey('C5', $set->weights['c_hazard']['FIRE']);
        $this->assertSame(1.0, (float) $set->weights['v_warning']['ALL']['V_WARNING_ACCESS_GAP_SCORE']);
    }

    public function test_locked_set_cannot_be_updated_until_unlocked()
    {
        $set = $this->makeSet(['is_locked' => true]);
        $admin = $this->admin();
        $payload = ['name' => 'เปลี่ยนชื่อ', 'weights' => CriteriaSchema::defaults()];

        $this->actingAs($admin)->put("/scoring-criteria/{$set->id}", $payload)->assertStatus(403);
        $this->assertSame('ชุดทดสอบ', $set->fresh()->name);

        $this->actingAs($admin)->post("/scoring-criteria/{$set->id}/unlock");
        $this->actingAs($admin)->put("/scoring-criteria/{$set->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('เปลี่ยนชื่อ', $set->fresh()->name);

        $this->actingAs($admin)->post("/scoring-criteria/{$set->id}/lock");
        $this->assertTrue($set->fresh()->is_locked);
    }

    public function test_activating_a_set_deactivates_the_others()
    {
        $a = $this->makeSet(['name' => 'A', 'is_active' => true]);
        $b = $this->makeSet(['name' => 'B']);

        $this->actingAs($this->admin())->post("/scoring-criteria/{$b->id}/activate");

        $this->assertFalse($a->fresh()->is_active);
        $this->assertTrue($b->fresh()->is_active);
        $this->assertSame(1, ScoringCriteriaSet::where('is_active', true)->count());
    }

    public function test_clone_copies_weights_as_an_inactive_unlocked_set()
    {
        $weights = CriteriaSchema::defaults();
        $weights['recovery']['ALL']['R1'] = 0.30;
        $weights['recovery']['ALL']['R2'] = 0.20;
        $source = $this->makeSet(['is_active' => true, 'is_locked' => true, 'weights' => $weights]);

        $this->actingAs($this->admin())
            ->post('/scoring-criteria', ['name' => 'สำเนา', 'copy_from' => $source->id])
            ->assertSessionHasNoErrors();

        $clone = ScoringCriteriaSet::where('name', 'สำเนา')->firstOrFail();
        $this->assertFalse($clone->is_active);
        $this->assertFalse($clone->is_locked);
        $this->assertSame($source->weights, $clone->weights);
    }

    public function test_only_inactive_unlocked_sets_can_be_deleted()
    {
        $admin = $this->admin();
        $active = $this->makeSet(['name' => 'ใช้งาน', 'is_active' => true]);
        $locked = $this->makeSet(['name' => 'ล็อก', 'is_locked' => true]);
        $free = $this->makeSet(['name' => 'ว่าง']);

        $this->actingAs($admin)->delete("/scoring-criteria/{$active->id}")->assertStatus(403);
        $this->actingAs($admin)->delete("/scoring-criteria/{$locked->id}")->assertStatus(403);
        $this->actingAs($admin)->delete("/scoring-criteria/{$free->id}")->assertSessionHasNoErrors();

        $this->assertModelExists($active);
        $this->assertModelExists($locked);
        $this->assertModelMissing($free);
    }

    public function test_names_must_be_unique()
    {
        $a = $this->makeSet(['name' => 'A']);
        $this->makeSet(['name' => 'B']);

        $this->actingAs($this->admin())
            ->put("/scoring-criteria/{$a->id}", ['name' => 'B', 'weights' => CriteriaSchema::defaults()])
            ->assertSessionHasErrors('name');
    }
}
