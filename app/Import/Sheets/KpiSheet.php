<?php

namespace App\Import\Sheets;

use App\Models\Community;
use App\Models\HealthRecord;
use App\Models\KpiRecord;
use App\Models\WelfareRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * งาน KPI (sheet "KPI_เพิ่มเติมได้"): one row per community per ASSESSMENT_DATE, into kpi_records. Staff enter
 * only how many were reached. The group size comes from the V_*_COUNT already imported by งานสวัสดิการสังคม /
 * งานสาธารณสุข (spec v5 §5.8–5.9, e.g. P_MEDICAL = KPI_MEDICAL_PLAN_READY_COUNT / V_MEDICAL_DEPENDENT_COUNT × 100).
 *
 * A KPI count above its group can't be right (the % would pass 100), but it only **warns** (owner decision
 * 2026-11-03). It is compared with that community's latest group data dated on or before the KPI's
 * ASSESSMENT_DATE; with no such data the row is just not compared.
 */
class KpiSheet extends CommunityDateSheet
{
    /**
     * KPI header → [group model, group column, group header, sheet label]. Only the 6 pairs spec §5.9 gives a
     * formula for. The 2 low-income KPIs (EXIT_VERIFIED, FOLLOWUP) have none and aren't compared, since people
     * who exited low income may no longer be in V_LOW_INCOME_COUNT.
     */
    private const GROUPS = [
        'KPI_ELDERLY_PLAN_COUNT' => [WelfareRecord::class, 'v_elderly_count', 'V_ELDERLY_COUNT', 'งานสวัสดิการสังคม'],
        'KPI_DISABLED_PLAN_COUNT' => [WelfareRecord::class, 'v_disabled_count', 'V_DISABLED_COUNT', 'งานสวัสดิการสังคม'],
        'KPI_CHILD_PLAN_COUNT' => [WelfareRecord::class, 'v_child_count', 'V_CHILD_COUNT', 'งานสวัสดิการสังคม'],
        'KPI_MEDICAL_PLAN_READY_COUNT' => [HealthRecord::class, 'v_medical_dependent_count', 'V_MEDICAL_DEPENDENT_COUNT', 'งานสาธารณสุข'],
        'KPI_BEDRIDDEN_PLAN_READY_COUNT' => [HealthRecord::class, 'v_bedridden_count', 'V_BEDRIDDEN_COUNT', 'งานสาธารณสุข'],
        'KPI_ASSISTED_EVAC_READY_COUNT' => [HealthRecord::class, 'v_assisted_evacuation_count', 'V_ASSISTED_EVACUATION_COUNT', 'งานสาธารณสุข'],
    ];

    protected function model(): string
    {
        return KpiRecord::class;
    }

    protected function valueColumns(): array
    {
        return [
            'KPI_LOW_INCOME_EXIT_VERIFIED_COUNT' => self::COUNT,
            'KPI_LOW_INCOME_FOLLOWUP_COUNT' => self::COUNT,
            'KPI_ELDERLY_PLAN_COUNT' => self::COUNT,
            'KPI_DISABLED_PLAN_COUNT' => self::COUNT,
            'KPI_CHILD_PLAN_COUNT' => self::COUNT,
            'KPI_MEDICAL_PLAN_READY_COUNT' => self::COUNT,
            'KPI_BEDRIDDEN_PLAN_READY_COUNT' => self::COUNT,
            'KPI_ASSISTED_EVAC_READY_COUNT' => self::COUNT,
        ];
    }

    public function crossChecks(array $rows): array
    {
        $communityIds = Community::pluck('id', 'code');
        $latest = [];
        // The community's latest group record on or before the date, cached per model.
        $groupRecord = function (string $model, string $code, string $date) use ($communityIds, &$latest): ?Model {
            $key = "{$model}|{$code}|{$date}";
            if (! array_key_exists($key, $latest)) {
                $latest[$key] = $model::where('community_id', $communityIds[$code] ?? 0)
                    ->whereDate('assessment_date', '<=', $date)->latest('assessment_date')->first();
            }

            return $latest[$key];
        };

        $checks = [];
        foreach (self::GROUPS as $kpi => [$model, $column, $groupHeader, $sheet]) {
            $warnings = [];
            $compared = 0;
            $noGroup = 0;
            foreach ($rows as $row) {
                $count = $row['values'][$kpi];
                if ($count === null) {
                    continue;
                }
                $group = $groupRecord($model, $row['values']['COMMUNITY_ID'], $row['values']['ASSESSMENT_DATE']);
                if (! $group || $group->{$column} === null) {
                    $noGroup++;

                    continue;
                }
                $compared++;
                if ($count > $group->{$column}) {
                    $warnings[] = [
                        'row' => $row['row'],
                        'column' => $kpi,
                        'message' => "{$kpi} = {$count} มากกว่า {$groupHeader} = {$group->{$column}} ({$sheet} วันที่ประเมิน "
                            .$group->assessment_date->format('d/m/Y').') ร้อยละจะเกิน 100',
                    ];
                }
            }

            $detail = $warnings
                ? 'เกินกลุ่มเป้าหมาย '.count($warnings).' แถว'
                : ($compared ? "ไม่เกินกลุ่มเป้าหมาย ({$compared} แถว)" : 'ยังไม่มีข้อมูลกลุ่มให้เทียบ');
            if ($noGroup && ($warnings || $compared)) {
                $detail .= " · ไม่มีข้อมูลกลุ่มให้เทียบ {$noGroup} แถว";
            }
            $checks[] = [
                'label' => "เทียบ {$kpi} กับ {$groupHeader} ({$sheet})",
                'status' => $warnings ? 'warning' : 'ok',
                'detail' => $detail,
                'warnings' => $warnings,
            ];
        }

        return $checks;
    }
}
