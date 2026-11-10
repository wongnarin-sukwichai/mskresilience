<?php

namespace App\Import\Sheets;

use App\Models\DisasterEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * งานป้องกันภัยเหตุการณ์ (sheet "งาน ปภ_เหตุการณ์"): one row per event, into disaster_events, keyed by the
 * staff-typed EVENT_ID. The rules agreed with the owner (2026-11-02):
 * - EVENT_ID must be EV + 4 digits, and a duplicate within the file is an error (ImportValidator's key check).
 * - An EVENT_ID already in the system is replaced, with a warning naming it.
 * - If that existing event belongs to another community or started on another date, it's an error: a different
 *   event was most likely given a used code.
 * EVENT_END may be blank (still going on), but not before EVENT_START.
 */
class EventSheet extends TableSheet
{
    protected function model(): string
    {
        return DisasterEvent::class;
    }

    protected function dateColumn(): string
    {
        return 'event_start';
    }

    public function rules(): array
    {
        return [
            'EVENT_ID' => self::EVENT_ID,
            'COMMUNITY_ID' => self::COMMUNITY,
            'COMMUNITY_NAME' => self::COMMUNITY_NAME,
            ...$this->valueColumns(),
        ];
    }

    protected function valueColumns(): array
    {
        return [
            'EVENT_START' => self::DATE,
            'EVENT_END' => self::OPTIONAL_DATE,
            'PRIMARY_HAZARD' => self::HAZARD,
            'RELATED_HAZARDS' => self::HAZARD_LIST,
            'MULTI_HAZARD_FLAG' => self::YES_NO,
            'EVENT_DESCRIPTION' => self::LONG_TEXT,
            'L1_HOUSES_DAMAGED_COUNT' => self::COUNT,
            'L2_INJURY' => self::COUNT,
            'L2_DEATH' => self::COUNT,
            'L3_DAMAGE_VALUE_THB' => self::DECIMAL,
            'L4_RECOVERY_DAYS' => self::COUNT,
            'L5_REPEAT_DAMAGE_COUNT' => self::COUNT,
            'R1_OBSERVED' => self::SCORE,
            'R2_OBSERVED' => self::SCORE,
            'R3_OBSERVED' => self::SCORE,
            'R4_OBSERVED' => self::SCORE,
            'R5_OBSERVED' => self::SCORE,
            'F1_EVENT_TOTAL_BUDGET_THB' => self::DECIMAL,
            'F2_EVENT_RESPONSE_COST_THB' => self::DECIMAL,
            'F3_EVENT_RECOVERY_COST_THB' => self::DECIMAL,
            'F4_EVENT_RISK_REDUCTION_BUDGET_THB' => self::DECIMAL,
            'F5_EVENT_RELIEF_COMPENSATION_THB' => self::DECIMAL,
        ];
    }

    public function keyColumns(): array
    {
        return ['EVENT_ID'];
    }

    protected function find(array $row): ?Model
    {
        return $this->query()->where('event_id', $row['EVENT_ID'])->first();
    }

    protected function newRecordAttributes(array $row): array
    {
        return ['event_id' => $row['EVENT_ID'], 'community_id' => $row['COMMUNITY_ID']];
    }

    protected function recordKey(Model $record): string
    {
        return $record->event_id;
    }

    public function crossChecks(array $rows): array
    {
        $endErrors = [];
        foreach ($rows as $row) {
            ['EVENT_START' => $start, 'EVENT_END' => $end] = $row['values'];
            if ($end !== null && $end < $start) {
                $endErrors[] = ['row' => $row['row'], 'column' => 'EVENT_END', 'message' => 'วันสิ้นสุด '.$this->dmy($end).' อยู่ก่อนวันเริ่ม '.$this->dmy($start)];
            }
        }

        $existing = DisasterEvent::with('community:id,code')->whereIn('event_id', array_column(array_column($rows, 'values'), 'EVENT_ID'))
            ->get()->keyBy('event_id');
        $clashes = [];
        $replaces = [];
        foreach ($rows as $row) {
            $v = $row['values'];
            if (! $event = $existing->get($v['EVENT_ID'])) {
                continue;
            }
            $was = "{$event->community->code} เริ่ม ".$this->dmy($event->event_start->toDateString());
            if ($event->community->code !== $v['COMMUNITY_ID'] || $event->event_start->toDateString() !== $v['EVENT_START']) {
                $clashes[] = [
                    'row' => $row['row'],
                    'column' => 'EVENT_ID',
                    'message' => "{$v['EVENT_ID']} มีในระบบแล้วเป็นเหตุการณ์ของ {$was} ไม่ตรงกับไฟล์ ({$v['COMMUNITY_ID']} เริ่ม "
                        .$this->dmy($v['EVENT_START']).') ถ้าเป็นเหตุการณ์ใหม่ กรุณาใช้รหัสใหม่',
                ];
            } else {
                $replaces[] = ['row' => $row['row'], 'column' => 'EVENT_ID', 'message' => "{$v['EVENT_ID']} มีในระบบแล้ว ({$was}) ข้อมูลเดิมจะถูกแทนที่"];
            }
        }

        return [
            [
                'label' => 'ตรวจ EVENT_END ไม่ก่อน EVENT_START',
                'status' => $endErrors ? 'error' : 'ok',
                'detail' => $endErrors ? 'วันสิ้นสุดอยู่ก่อนวันเริ่ม '.count($endErrors).' แถว' : 'ถูกต้องทุกแถว',
                'warnings' => [],
                'errors' => $endErrors,
            ],
            [
                'label' => 'เทียบ EVENT_ID กับเหตุการณ์ในระบบ',
                'status' => $clashes ? 'error' : ($replaces ? 'warning' : 'ok'),
                'detail' => match (true) {
                    (bool) $clashes => 'รหัสซ้ำกับเหตุการณ์อื่นในระบบ '.count($clashes).' แถว',
                    (bool) $replaces => 'มีในระบบแล้ว จะแทนที่ '.count($replaces).' เหตุการณ์',
                    default => 'เป็นเหตุการณ์ใหม่ทั้งหมด',
                },
                'warnings' => $replaces,
                'errors' => $clashes,
            ],
        ];
    }

    private function dmy(string $ymd): string
    {
        [$y, $m, $d] = explode('-', $ymd);

        return "{$d}/{$m}/{$y}";
    }
}
