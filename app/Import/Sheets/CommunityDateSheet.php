<?php

namespace App\Import\Sheets;

use Illuminate\Database\Eloquent\Model;

/**
 * A sheet with one row per community per ASSESSMENT_DATE (ASSESSMENT_DATE, COMMUNITY_ID, COMMUNITY_NAME, then
 * value columns), saved into its own table. Welfare, health, education, engineering, public relations, disaster
 * and KPI all have this shape. A subclass only declares its model and its value columns. The model must also have
 * assessment_date (cast to date).
 *
 * Rows are replaced per (community, date), with the old values kept so cancel() can restore them (TableSheet).
 */
abstract class CommunityDateSheet extends TableSheet
{
    public function rules(): array
    {
        return [
            'ASSESSMENT_DATE' => self::DATE,
            'COMMUNITY_ID' => self::COMMUNITY,
            'COMMUNITY_NAME' => self::COMMUNITY_NAME,
            ...$this->valueColumns(),
        ];
    }

    public function keyColumns(): array
    {
        return ['COMMUNITY_ID', 'ASSESSMENT_DATE'];
    }

    /**
     * Looked up by whereDate so the match doesn't depend on how the driver stores the date column.
     */
    protected function find(array $row): ?Model
    {
        return $this->query()->where('community_id', $row['COMMUNITY_ID'])->whereDate('assessment_date', $row['ASSESSMENT_DATE'])->first();
    }

    protected function newRecordAttributes(array $row): array
    {
        return ['community_id' => $row['COMMUNITY_ID'], 'assessment_date' => $row['ASSESSMENT_DATE']];
    }

    protected function recordKey(Model $record): string
    {
        return $record->community->code.'|'.$record->assessment_date->toDateString();
    }
}
