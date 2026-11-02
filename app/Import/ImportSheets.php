<?php

namespace App\Import;

use App\Enums\WorkGroup;
use App\Import\Sheets\ImportSheet;
use App\Import\Sheets\WelfareSheet;

/**
 * Which กลุ่มงาน can actually be imported so far. Built sheet by sheet, starting with งานสวัสดิการสังคม
 * (2026-11-02). A group returning null still has its template and example, but upload is refused.
 */
class ImportSheets
{
    public static function for(WorkGroup $group): ?ImportSheet
    {
        return match ($group) {
            WorkGroup::Welfare => new WelfareSheet,
            default => null,
        };
    }
}
