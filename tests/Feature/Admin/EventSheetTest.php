<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use App\Import\ImportColumns;
use App\Import\ImportExamples;
use App\Models\Community;
use App\Models\DisasterEvent;
use App\Models\ImportBatch;
use App\Models\User;
use Database\Seeders\CommunitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * งานป้องกันภัยเหตุการณ์ (EventSheet): keyed by the staff-typed EVENT_ID, with the rules agreed 2026-11-02.
 */
class EventSheetTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CommunitySeeder::class);
        $this->staff = User::factory()->create(['role' => UserRole::Staff, 'work_groups' => [WorkGroup::DisasterEvent]]);
    }

    /**
     * A valid event row; $override is keyed by header.
     *
     * @param  array<string, mixed>  $override
     * @return list<mixed>
     */
    private function event(array $override = []): array
    {
        $row = array_combine(ImportColumns::for(WorkGroup::DisasterEvent), [
            'EV0001', 'R001', null, '18/05/2026', '20/05/2026', 'FLOOD', 'STORM', 'ใช่', 'น้ำท่วมพื้นที่ลุ่มต่ำ',
            42, 2, 0, 3850000, 18, 1, 4, 4, 3, 3, 4, 1100000, 180000, 350000, 420000, 150000,
        ]);

        return array_values(array_replace($row, $override));
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function validateFile(array $rows): array
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([ImportColumns::for(WorkGroup::DisasterEvent), ...$rows], null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->actingAs($this->staff)->post('/data-import/disaster_event/validate', ['file' => new UploadedFile($path, 'events.xlsx', null, null, true)]);

        $props = null;
        $this->actingAs($this->staff)->get('/data-import/disaster_event')->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props['preview'];
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function import(array $rows): ImportBatch
    {
        $preview = $this->validateFile($rows);
        $this->assertSame([], $preview['errors']);
        $this->actingAs($this->staff)->post('/data-import/disaster_event/confirm', ['token' => $preview['token']]);

        return ImportBatch::latest('id')->firstOrFail();
    }

    public function test_the_web_examples_pass_validation()
    {
        $this->assertSame([], $this->validateFile(ImportExamples::for(WorkGroup::DisasterEvent))['errors']);
    }

    public function test_event_is_saved_with_hazard_keys_flag_and_optional_end()
    {
        $this->import([
            $this->event(['RELATED_HAZARDS' => 'storm, PM2.5', 'MULTI_HAZARD_FLAG' => 'ใช่']),
            $this->event(['EVENT_ID' => 'ev0002', 'COMMUNITY_ID' => 'R002', 'EVENT_START' => Date::stringToExcel('2026-03-04'), 'EVENT_END' => null,
                'PRIMARY_HAZARD' => 'pm25', 'RELATED_HAZARDS' => null, 'MULTI_HAZARD_FLAG' => 'ไม่ใช่', 'L3_DAMAGE_VALUE_THB' => '1,260,000.50']),
        ]);

        $first = DisasterEvent::where('event_id', 'EV0001')->sole();
        $this->assertSame(['FLOOD', 'STORM,PM25', true, '2026-05-18', '2026-05-20'], [
            $first->primary_hazard, $first->related_hazards, $first->multi_hazard_flag, $first->event_start->toDateString(), $first->event_end->toDateString(),
        ]);
        $second = DisasterEvent::where('event_id', 'EV0002')->sole();
        $this->assertSame(['PM25', null, false, null, '1260000.50', '2026-03-04'], [
            $second->primary_hazard, $second->related_hazards, $second->multi_hazard_flag, $second->event_end, $second->l3_damage_value_thb, $second->event_start->toDateString(),
        ]);
        $this->assertSame(Community::where('code', 'R002')->value('id'), $second->community_id);
    }

    public function test_row_level_rules()
    {
        $messages = array_column($this->validateFile([
            $this->event(['EVENT_ID' => 'E001']),
            $this->event(['EVENT_ID' => 'EV0002', 'PRIMARY_HAZARD' => 'EARTHQUAKE', 'RELATED_HAZARDS' => 'FLOOD, SNOW', 'MULTI_HAZARD_FLAG' => 'maybe']),
            $this->event(['EVENT_ID' => null, 'PRIMARY_HAZARD' => null]),
            $this->event(['EVENT_ID' => 'EV0003']),
            $this->event(['EVENT_ID' => 'EV0003']),
        ])['errors'], 'message');

        $this->assertContains('"E001" ไม่ใช่รหัสเหตุการณ์ ต้องเป็น EV ตามด้วยตัวเลข 4 หลัก เช่น EV0001', $messages);
        $this->assertContains('"EARTHQUAKE" ไม่ใช่ภัยที่ระบบรู้จัก (FLOOD, FIRE, STORM, HEAT, PM2.5)', $messages);
        $this->assertContains('"SNOW" ไม่ใช่ภัยที่ระบบรู้จัก (FLOOD, FIRE, STORM, HEAT, PM2.5) คั่นหลายภัยด้วยเครื่องหมายจุลภาค', $messages);
        $this->assertContains('"maybe" ต้องเป็น ใช่ หรือ ไม่ใช่', $messages);
        $this->assertContains('ต้องกรอก EVENT_ID', $messages);
        $this->assertContains('ต้องกรอก PRIMARY_HAZARD', $messages);
        $this->assertContains('ข้อมูลซ้ำกับแถวที่ 5 (EVENT_ID เดียวกัน)', $messages);
    }

    public function test_end_before_start_is_an_error()
    {
        $preview = $this->validateFile([$this->event(['EVENT_START' => '20/05/2026', 'EVENT_END' => '18/05/2026'])]);

        $this->assertContains('วันสิ้นสุด 18/05/2026 อยู่ก่อนวันเริ่ม 20/05/2026', array_column($preview['errors'], 'message'));
        $this->assertSame('error', array_column($preview['checks'], null, 'label')['ตรวจ EVENT_END ไม่ก่อน EVENT_START']['status']);
    }

    public function test_same_event_id_replaces_with_a_warning_and_cancel_restores_it()
    {
        $first = $this->import([$this->event(['L2_INJURY' => 2])]);

        $preview = $this->validateFile([$this->event(['L2_INJURY' => 5, 'EVENT_END' => '25/05/2026'])]);
        $this->assertSame([], $preview['errors']);
        $this->assertContains('EV0001 มีในระบบแล้ว (R001 เริ่ม 18/05/2026) ข้อมูลเดิมจะถูกแทนที่', array_column($preview['warnings'], 'message'));
        $this->assertSame(['warning', 'มีในระบบแล้ว จะแทนที่ 1 เหตุการณ์'], array_values(array_slice(array_column($preview['checks'], null, 'label')['เทียบ EVENT_ID กับเหตุการณ์ในระบบ'], 1)));
        $this->actingAs($this->staff)->post('/data-import/disaster_event/confirm', ['token' => $preview['token']]);
        $second = ImportBatch::latest('id')->first();
        $this->assertSame([1, 5, '2026-05-25'], [DisasterEvent::count(), DisasterEvent::sole()->l2_injury, DisasterEvent::sole()->event_end->toDateString()]);

        // The viewer keys rows by EVENT_ID too.
        $this->assertSame(['replaced' => 1], $this->actingAs($this->staff)->getJson("/import-history/{$first->id}/data")->json('counts'));

        $this->actingAs($this->staff)->delete("/import-history/{$second->id}")->assertSessionHas('status');
        $event = DisasterEvent::sole();
        $this->assertSame([2, '2026-05-20', $first->id], [$event->l2_injury, $event->event_end->toDateString(), $event->import_batch_id]);
    }

    public function test_used_event_id_for_another_community_or_start_is_an_error()
    {
        $this->import([$this->event()]);

        $messages = array_column($this->validateFile([
            $this->event(['COMMUNITY_ID' => 'R002']),
        ])['errors'], 'message');
        $this->assertContains('EV0001 มีในระบบแล้วเป็นเหตุการณ์ของ R001 เริ่ม 18/05/2026 ไม่ตรงกับไฟล์ (R002 เริ่ม 18/05/2026) ถ้าเป็นเหตุการณ์ใหม่ กรุณาใช้รหัสใหม่', $messages);

        $messages = array_column($this->validateFile([$this->event(['EVENT_START' => '17/05/2026'])])['errors'], 'message');
        $this->assertContains('EV0001 มีในระบบแล้วเป็นเหตุการณ์ของ R001 เริ่ม 18/05/2026 ไม่ตรงกับไฟล์ (R001 เริ่ม 17/05/2026) ถ้าเป็นเหตุการณ์ใหม่ กรุณาใช้รหัสใหม่', $messages);
        $this->assertSame(1, DisasterEvent::count());
    }

    public function test_events_block_deleting_their_community()
    {
        $this->import([$this->event()]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->delete('/communities/'.Community::where('code', 'R001')->value('id'))->assertSessionHas('error');
    }
}
