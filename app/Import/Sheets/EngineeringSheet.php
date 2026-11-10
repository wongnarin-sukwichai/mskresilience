<?php

namespace App\Import\Sheets;

use App\Models\EngineeringRecord;

/**
 * งานสำนักช่าง: one row per community per ASSESSMENT_DATE, into engineering_records.
 */
class EngineeringSheet extends CommunityDateSheet
{
    protected function model(): string
    {
        return EngineeringRecord::class;
    }

    protected function valueColumns(): array
    {
        return [
            'V_INFRA_GAP_COUNT_FLOOD' => self::SCORE,
            'V_INFRA_GAP_COUNT_FIRE' => self::SCORE,
            'V_INFRA_GAP_COUNT_STORM' => self::SCORE,
            'V_INFRA_GAP_COUNT_HEAT' => self::SCORE,
            'V_INFRA_GAP_COUNT_PM25' => self::SCORE,
            'C5_1_GREENBLUE_SCORE' => self::SCORE,
            'C5_2_NBS_SCORE' => self::SCORE,
            'C5_3_MAINTENANCE_SCORE' => self::SCORE,
        ];
    }
}
