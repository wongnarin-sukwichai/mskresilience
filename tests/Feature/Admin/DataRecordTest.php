<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Models\Community;
use App\Models\DisasterEvent;
use App\Models\ImportBatch;
use App\Models\User;
use App\Models\WelfareRecord;
use Database\Seeders\CommunitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * แก้ไขข้อมูล in the web (TOR 4.1.1(6)-2): add, edit and delete one row, checked with the import rules, each a
 * cancellable batch in the history and the audit trail.
 */
class DataRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CommunitySeeder::class);
    }

    private function staff(array $groups = [WorkGroup::Welfare]): User
    {
        return User::factory()->create(['role' => UserRole::Staff, 'work_groups' => $groups]);
    }

    /** @return array<string, string|null> */
    private function welfare(string $code = 'R001', array $values = []): array
    {
        return ['ASSESSMENT_DATE' => '2025-01-20', 'COMMUNITY_ID' => $code, 'COMMUNITY_NAME' => null,
            'V_ELDERLY_COUNT' => '100', 'V_CHILD_COUNT' => '20', 'V_DISABLED_COUNT' => null, 'V_LOW_INCOME_COUNT' => '5', ...$values];
    }

    private function record(string $code = 'R001'): WelfareRecord
    {
        return WelfareRecord::where('community_id', Community::where('code', $code)->value('id'))->sole();
    }

    public function test_staff_adds_a_row_checked_with_the_import_rules()
    {
        $staff = $this->staff();
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $this->welfare()])
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $record = $this->record();
        $this->assertSame(100, $record->v_elderly_count);
        $this->assertNull($record->v_disabled_count);
        $batch = ImportBatch::sole();
        $this->assertSame(['web_add', 'active', $record->import_batch_id], [$batch->source, $batch->status, $batch->id]);

        // Same rules as a file: a bad value is an error on its field, nothing saved.
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $this->welfare('R002', ['V_CHILD_COUNT' => '-3'])])
            ->assertSessionHasErrors('V_CHILD_COUNT');
        // Adding an existing community + date is refused: edit the row instead.
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $this->welfare()])->assertSessionHasErrors('_');
        $this->assertSame(1, ImportBatch::count());
    }

    public function test_edit_keeps_the_key_and_can_be_cancelled_back_to_the_old_values()
    {
        $staff = $this->staff();
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $this->welfare()]);
        $record = $this->record();

        // A changed date or community in the form is ignored: the key is locked.
        $this->actingAs($staff)->put("/data-records/welfare/{$record->id}", ['values' => [
            'ASSESSMENT_DATE' => '2030-01-01', 'COMMUNITY_ID' => 'R005', 'V_ELDERLY_COUNT' => '150', 'V_DISABLED_COUNT' => '3',
        ]])->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame([150, 3, '2025-01-20'], [$record->v_elderly_count, $record->v_disabled_count, $record->assessment_date->toDateString()]);
        $edit = ImportBatch::where('source', 'web_edit')->sole();
        $this->assertSame($edit->id, $record->import_batch_id);

        $log = Activity::where('event', 'web_edit')->sole();
        $this->assertSame(['V_ELDERLY_COUNT' => '100', 'V_DISABLED_COUNT' => null], $log->properties['old']);
        $this->assertSame(['V_ELDERLY_COUNT' => '150', 'V_DISABLED_COUNT' => '3'], $log->properties['attributes']);

        $this->actingAs($staff)->delete("/import-history/{$edit->id}")->assertSessionHas('status');
        $record->refresh();
        $this->assertSame([100, null], [$record->v_elderly_count, $record->v_disabled_count]);

        $this->actingAs($staff)->put("/data-records/welfare/{$record->id}", ['values' => $this->welfare()])->assertSessionHas('error');
    }

    public function test_delete_removes_a_wrong_row_and_cancel_puts_it_back()
    {
        $staff = $this->staff();
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $this->welfare()]);
        $record = $this->record();

        $this->actingAs($staff)->delete("/data-records/welfare/{$record->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, WelfareRecord::count());
        $delete = ImportBatch::where('source', 'web_delete')->sole();

        // The history viewer shows the deleted row from the batch.
        $this->actingAs($staff)->getJson("/import-history/{$delete->id}/data")->assertOk()->assertJsonPath('rows.0.status', 'deleted');

        // The add can't be cancelled while its row is deleted (cancel the delete first).
        $add = ImportBatch::where('source', 'web_add')->sole();
        $this->actingAs($staff)->delete("/import-history/{$add->id}")->assertSessionHas('error');

        $this->actingAs($staff)->delete("/import-history/{$delete->id}")->assertSessionHas('status');
        $back = $this->record();
        $this->assertSame([$record->id, 100, $add->id], [$back->id, $back->v_elderly_count, $back->import_batch_id]);
    }

    public function test_a_deleted_row_that_was_added_again_cannot_be_restored()
    {
        $staff = $this->staff();
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $this->welfare()]);
        $this->actingAs($staff)->delete("/data-records/welfare/{$this->record()->id}");
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $this->welfare()]);

        $delete = ImportBatch::where('source', 'web_delete')->sole();
        $this->actingAs($staff)->delete("/import-history/{$delete->id}")->assertSessionHas('error');
    }

    public function test_only_the_granted_groups_can_be_edited()
    {
        $staff = $this->staff([WorkGroup::Health]);
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $this->welfare()])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Member]))->get('/data-records')->assertForbidden();

        $this->actingAs($staff)->get('/data-records?group=welfare')->assertInertia(fn ($page) => $page
            ->component('admin/DataRecords')
            ->where('group', 'health')
            ->where('groups', [['value' => 'health', 'label' => WorkGroup::Health->label()]]));
    }

    public function test_the_list_shows_rows_with_their_values_and_locked_columns()
    {
        $staff = $this->staff();
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $this->welfare()]);
        $this->actingAs($staff)->post('/data-records/welfare', ['values' => $this->welfare('R002')]);

        $this->actingAs($staff)->get('/data-records?group=welfare&community=R002')->assertInertia(fn ($page) => $page
            ->has('records.data', 1)
            ->where('records.data.0.values.COMMUNITY_ID', 'R002')
            ->where('records.data.0.values.V_ELDERLY_COUNT', '100')
            ->where('columns.0.name', 'ASSESSMENT_DATE')
            ->where('columns.0.locked', true)
            ->where('columns.3.locked', false));
    }

    public function test_events_keep_their_community_and_start_and_check_the_end_date()
    {
        $staff = $this->staff([WorkGroup::DisasterEvent]);
        $event = ['EVENT_ID' => 'EV0001', 'COMMUNITY_ID' => 'R001', 'EVENT_START' => '2025-01-10', 'EVENT_END' => '2025-01-12',
            'PRIMARY_HAZARD' => 'FLOOD', 'MULTI_HAZARD_FLAG' => 'ไม่ใช่', 'L2_DEATH' => '0'];
        $this->actingAs($staff)->post('/data-records/disaster_event', ['values' => $event])->assertSessionHasNoErrors();
        $record = DisasterEvent::sole();
        $this->assertFalse($record->multi_hazard_flag);

        $this->actingAs($staff)->put("/data-records/disaster_event/{$record->id}", ['values' => ['EVENT_END' => '2025-01-01']])
            ->assertSessionHasErrors('EVENT_END');
        $this->actingAs($staff)->put("/data-records/disaster_event/{$record->id}", ['values' => ['L2_DEATH' => '2', 'EVENT_START' => '2024-01-01']])
            ->assertSessionHasNoErrors();
        $record->refresh();
        $this->assertSame([2, '2025-01-10'], [$record->l2_death, $record->event_start->toDateString()]);
    }
}
