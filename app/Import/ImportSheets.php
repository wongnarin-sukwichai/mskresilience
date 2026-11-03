<?php

namespace App\Import;

use App\Enums\WorkGroup;
use App\Import\Sheets\DisasterSheet;
use App\Import\Sheets\EducationSheet;
use App\Import\Sheets\EngineeringSheet;
use App\Import\Sheets\EventSheet;
use App\Import\Sheets\HealthSheet;
use App\Import\Sheets\ImportSheet;
use App\Import\Sheets\KpiSheet;
use App\Import\Sheets\PublicRelationsSheet;
use App\Import\Sheets\WelfareSheet;

/**
 * Which กลุ่มงาน can actually be imported so far. Built sheet by sheet: งานสวัสดิการสังคม (2026-11-02), then
 * สาธารณสุข, กองการศึกษา, สำนักช่าง and ประชาสัมพันธ์ (2026-11-03, all CommunityDateSheet). A group returning null still has its template and example, but upload is refused.
 */
class ImportSheets
{
    public static function for(WorkGroup $group): ?ImportSheet
    {
        return match ($group) {
            WorkGroup::DisasterEvent => new EventSheet,
            WorkGroup::Disaster => new DisasterSheet,
            WorkGroup::Welfare => new WelfareSheet,
            WorkGroup::Health => new HealthSheet,
            WorkGroup::Education => new EducationSheet,
            WorkGroup::Engineering => new EngineeringSheet,
            WorkGroup::PublicRelations => new PublicRelationsSheet,
            WorkGroup::Kpi => new KpiSheet,
            default => null,
        };
    }

    /**
     * Every importable sheet, e.g. for checks across all imported data.
     *
     * @return list<ImportSheet>
     */
    public static function all(): array
    {
        return array_values(array_filter(array_map(fn ($g) => self::for($g), WorkGroup::cases())));
    }
}
