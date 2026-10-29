<?php

namespace App\Enums;

/**
 * The 8 กลุ่มงาน — one per input sheet of the client's data_input_mcr2030_v4.xlsx (confirmed with the project
 * owner 2026-10-29). Fixed in code, not admin-editable: each group is tied to a sheet the importer reads, so a
 * new group always needs an importer change anyway. Admins grant users the groups they may import
 * (users.work_groups); see User::canImport().
 */
enum WorkGroup: string
{
    case DisasterEvent = 'disaster_event';
    case Disaster = 'disaster';
    case Health = 'health';
    case Welfare = 'welfare';
    case Education = 'education';
    case Engineering = 'engineering';
    case PublicRelations = 'public_relations';
    case Kpi = 'kpi';

    /**
     * Display name (product decision — the two งาน ปภ. sheets and the KPI sheet are renamed; the rest use the
     * sheet name as-is).
     */
    public function label(): string
    {
        return match ($this) {
            self::DisasterEvent => 'งานป้องกันภัยเหตุการณ์',
            self::Disaster => 'งานป้องกันภัย',
            self::Health => 'งานสาธารณสุข',
            self::Welfare => 'งานสวัสดิการสังคม',
            self::Education => 'งานกองการศึกษา',
            self::Engineering => 'งานสำนักช่าง',
            self::PublicRelations => 'งานประชาสัมพันธ์',
            self::Kpi => 'งาน KPI',
        };
    }

    /**
     * The sheet name in the import workbook, trimmed — the real "งาน ปภ1 " sheet has a trailing space, so the
     * importer must compare trimmed names.
     */
    public function sheetName(): string
    {
        return match ($this) {
            self::DisasterEvent => 'งาน ปภ_เหตุการณ์',
            self::Disaster => 'งาน ปภ1',
            self::Health => 'งานสาธารณสุข',
            self::Welfare => 'งานสวัสดิการสังคม',
            self::Education => 'งานกองการศึกษา',
            self::Engineering => 'งานสำนักช่าง',
            self::PublicRelations => 'งานประชาสัมพันธ์',
            self::Kpi => 'KPI_เพิ่มเติมได้',
        };
    }

    /**
     * For the frontend: [{value, label, sheet}] in workbook order.
     *
     * @return list<array{value: string, label: string, sheet: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $g) => ['value' => $g->value, 'label' => $g->label(), 'sheet' => $g->sheetName()], self::cases());
    }
}
