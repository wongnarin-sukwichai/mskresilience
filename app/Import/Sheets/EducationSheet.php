<?php

namespace App\Import\Sheets;

use App\Models\EducationRecord;

/**
 * งานกองการศึกษา: one row per community per ASSESSMENT_DATE, into education_records.
 */
class EducationSheet extends CommunityDateSheet
{
    protected function model(): string
    {
        return EducationRecord::class;
    }

    protected function valueColumns(): array
    {
        return [
            'C4_4_SCHOOL_DRR_EDUCATION_SCORE' => self::SCORE,
        ];
    }
}
