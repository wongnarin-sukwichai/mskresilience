<?php

namespace App\Import\Sheets;

use App\Models\WelfareRecord;

/**
 * งานสวัสดิการสังคม: one row per community per ASSESSMENT_DATE, into welfare_records.
 */
class WelfareSheet extends CommunityDateSheet
{
    protected function model(): string
    {
        return WelfareRecord::class;
    }

    protected function valueColumns(): array
    {
        return [
            'V_ELDERLY_COUNT' => self::COUNT,
            'V_CHILD_COUNT' => self::COUNT,
            'V_DISABLED_COUNT' => self::COUNT,
            'V_LOW_INCOME_COUNT' => self::COUNT,
        ];
    }
}
