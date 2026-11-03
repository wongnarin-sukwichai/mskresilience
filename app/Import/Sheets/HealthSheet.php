<?php

namespace App\Import\Sheets;

use App\Models\HealthRecord;

/**
 * งานสาธารณสุข: one row per community per ASSESSMENT_DATE, into health_records.
 */
class HealthSheet extends CommunityDateSheet
{
    protected function model(): string
    {
        return HealthRecord::class;
    }

    protected function valueColumns(): array
    {
        return [
            'V_BEDRIDDEN_COUNT' => self::COUNT,
            'V_MEDICAL_DEPENDENT_COUNT' => self::COUNT,
            'V_ASSISTED_EVACUATION_COUNT' => self::COUNT,
            'V_COMMUNICATION_ASSISTANCE_COUNT' => self::COUNT,
            'V_NCD_COUNT' => self::COUNT,
        ];
    }
}
