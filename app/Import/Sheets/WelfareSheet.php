<?php

namespace App\Import\Sheets;

use App\Models\ImportBatch;
use App\Models\WelfareRecord;

/**
 * งานสวัสดิการสังคม: one row per community per ASSESSMENT_DATE, into welfare_records.
 */
class WelfareSheet implements ImportSheet
{
    /** Header → welfare_records column, for the value columns. */
    private const VALUE_COLUMNS = [
        'V_ELDERLY_COUNT' => 'v_elderly_count',
        'V_CHILD_COUNT' => 'v_child_count',
        'V_DISABLED_COUNT' => 'v_disabled_count',
        'V_LOW_INCOME_COUNT' => 'v_low_income_count',
    ];

    public function rules(): array
    {
        return [
            'ASSESSMENT_DATE' => self::DATE,
            'COMMUNITY_ID' => self::COMMUNITY,
            'COMMUNITY_NAME' => self::COMMUNITY_NAME,
            ...array_fill_keys(array_keys(self::VALUE_COLUMNS), self::COUNT),
        ];
    }

    public function keyColumns(): array
    {
        return ['COMMUNITY_ID', 'ASSESSMENT_DATE'];
    }

    public function existing(array $rows): array
    {
        $records = collect($rows)->map(fn ($row) => WelfareRecord::where('community_id', $row['COMMUNITY_ID'])
            ->whereDate('assessment_date', $row['ASSESSMENT_DATE'])->first())->filter();

        $batches = ImportBatch::with('user:id,name')->whereIn('id', $records->pluck('import_batch_id')->filter()->unique())
            ->latest()->get()
            ->map(fn (ImportBatch $b) => [
                'createdAt' => $b->created_at?->toIso8601String(),
                'user' => $b->user?->name,
                'rows' => $records->where('import_batch_id', $b->id)->count(),
            ])->values()->all();

        return ['count' => $records->count(), 'batches' => $batches];
    }

    public function save(array $rows, ImportBatch $batch): void
    {
        foreach ($rows as $row) {
            $values = ['import_batch_id' => $batch->id];
            foreach (self::VALUE_COLUMNS as $header => $column) {
                $values[$column] = $row[$header];
            }

            // Look up by whereDate so the match doesn't depend on how the driver stores the date column.
            $record = WelfareRecord::where('community_id', $row['COMMUNITY_ID'])
                ->whereDate('assessment_date', $row['ASSESSMENT_DATE'])->first()
                ?? new WelfareRecord(['community_id' => $row['COMMUNITY_ID'], 'assessment_date' => $row['ASSESSMENT_DATE']]);
            $record->fill($values)->save();
        }
    }
}
