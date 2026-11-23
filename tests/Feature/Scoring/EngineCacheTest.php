<?php

namespace Tests\Feature\Scoring;

use App\Models\Community;
use App\Models\ImportBatch;
use App\Models\User;
use App\Models\WelfareRecord;
use App\Scoring\RiskEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Engine results are cached until the data changes (TOR 4.4-3, 20 users at once). Writing with DB::table() skips
 * model events, so it proves a result came from the cache; writing through a model must drop it.
 */
class EngineCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scoring.cache' => true]);
    }

    private function communityCount(): int
    {
        return count((new RiskEngine)->run()['communities']);
    }

    public function test_a_second_run_comes_from_the_cache_until_a_model_changes()
    {
        Community::create(['code' => 'R001', 'name' => 'หนึ่ง']);
        $this->assertSame(1, $this->communityCount());

        // Bypasses model events: the cached result is still served.
        DB::table('communities')->insert(['code' => 'R002', 'name' => 'สอง', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, $this->communityCount());

        // A model write flushes the cache.
        Community::create(['code' => 'R003', 'name' => 'สาม']);
        $this->assertSame(3, $this->communityCount());
    }

    public function test_record_and_batch_writes_flush_the_cache()
    {
        $community = Community::create(['code' => 'R001', 'name' => 'หนึ่ง']);
        $this->communityCount();
        DB::table('communities')->insert(['code' => 'R002', 'name' => 'สอง', 'created_at' => now(), 'updated_at' => now()]);

        $user = User::factory()->create();
        ImportBatch::create([
            'work_group' => 'welfare', 'user_id' => $user->id, 'original_filename' => 'a.xlsx', 'stored_path' => 'x',
            'row_count' => 1, 'replaced_count' => 0, 'community_count' => 1,
        ]);
        $this->assertSame(2, $this->communityCount());

        DB::table('communities')->insert(['code' => 'R003', 'name' => 'สาม', 'created_at' => now(), 'updated_at' => now()]);
        $record = new WelfareRecord;
        $record->forceFill(['community_id' => $community->id, 'assessment_date' => '2025-01-01', 'v_elderly_count' => 5])->save();
        $this->assertSame(3, $this->communityCount());
    }

    public function test_the_cache_can_be_switched_off()
    {
        config(['scoring.cache' => false]);
        Community::create(['code' => 'R001', 'name' => 'หนึ่ง']);
        $this->communityCount();
        DB::table('communities')->insert(['code' => 'R002', 'name' => 'สอง', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(2, $this->communityCount());
    }
}
