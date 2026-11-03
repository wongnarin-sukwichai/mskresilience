<?php

namespace App\Import\Sheets;

use App\Models\PublicRelationsRecord;

/**
 * งานประชาสัมพันธ์: one row per community per ASSESSMENT_DATE, into public_relations_records.
 */
class PublicRelationsSheet extends CommunityDateSheet
{
    protected function model(): string
    {
        return PublicRelationsRecord::class;
    }

    protected function valueColumns(): array
    {
        return [
            'V_WARNING_ACCESS_GAP_SCORE' => self::SCORE,
            'C4_3_RISK_COMM_SCORE' => self::SCORE,
        ];
    }
}
