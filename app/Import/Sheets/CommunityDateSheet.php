<?php

namespace App\Import\Sheets;

use App\Models\ImportBatch;
use App\Models\ImportReplacedRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A sheet with one row per community per ASSESSMENT_DATE (ASSESSMENT_DATE, COMMUNITY_ID, COMMUNITY_NAME, then
 * value columns), saved into its own table. Welfare, health, education, engineering and public relations all
 * have this shape. A subclass only declares its model and its value columns. Each value column is stored in
 * the table column with the lower-cased header name (V_ELDERLY_COUNT → v_elderly_count).
 *
 * Rows are replaced per (community, date). Before a row is overwritten its old values are kept in
 * import_replaced_rows, so cancel() can restore them.
 */
abstract class CommunityDateSheet implements ImportSheet
{
    /**
     * The table's model. It must have community_id, assessment_date (cast to date), import_batch_id, the value
     * columns, and a community() relation.
     *
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    /**
     * Value header → rule (ImportSheet::COUNT, SCORE, DECIMAL, PERCENT or TEXT), in template order.
     *
     * @return array<string, string>
     */
    abstract protected function valueColumns(): array;

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

    public function referencesCommunity(int $communityId): bool
    {
        return $this->query()->where('community_id', $communityId)->exists();
    }

    public function crossChecks(array $rows): array
    {
        return [];
    }

    public function existing(array $rows): array
    {
        $records = collect($rows)->map(fn ($row) => $this->find($row))->filter();

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
        $columns = $this->tableColumns();

        foreach ($rows as $row) {
            $values = ['import_batch_id' => $batch->id];
            foreach ($columns as $header => $column) {
                $values[$column] = $row[$header];
            }

            if ($record = $this->find($row)) {
                $batch->replacedRows()->create([
                    'record_id' => $record->id,
                    'previous_batch_id' => $record->import_batch_id,
                    'previous' => $record->only(array_values($columns)),
                ]);
            } else {
                $model = $this->model();
                $record = new $model;
                $record->forceFill(['community_id' => $row['COMMUNITY_ID'], 'assessment_date' => $row['ASSESSMENT_DATE']]);
            }
            $record->forceFill($values)->save();
        }
    }

    public function rowStatuses(ImportBatch $batch): array
    {
        $key = fn (Model $r) => $r->community->code.'|'.$r->assessment_date->toDateString();
        $statuses = [];

        $replaced = ImportReplacedRow::where('previous_batch_id', $batch->id)
            ->whereHas('batch', fn ($q) => $q->whereNull('cancelled_at'))->get(['import_batch_id', 'record_id']);
        $records = $this->query()->with('community:id,code')
            ->where(fn ($q) => $q->where('import_batch_id', $batch->id)->orWhereIn('id', $replaced->pluck('record_id')))
            ->get()->keyBy('id');

        // The first later batch that overwrote a row is the one named, even if another one replaced it again since.
        foreach ($replaced->sortBy('import_batch_id') as $r) {
            if ($record = $records->get($r->record_id)) {
                $statuses[$key($record)] ??= ['status' => 'replaced', 'by' => $r->import_batch_id];
            }
        }
        foreach ($records->where('import_batch_id', $batch->id) as $record) {
            $statuses[$key($record)] = ['status' => 'active'];
        }

        return $statuses;
    }

    public function cancel(ImportBatch $batch): void
    {
        $kept = $batch->replacedRows()->get()->keyBy('record_id');

        foreach ($this->query()->where('import_batch_id', $batch->id)->get() as $record) {
            if ($old = $kept->get($record->id)) {
                $record->forceFill([...$old->previous, 'import_batch_id' => $old->previous_batch_id])->save();
            } else {
                $record->delete();
            }
        }
    }

    /**
     * Value header → table column.
     *
     * @return array<string, string>
     */
    private function tableColumns(): array
    {
        $headers = array_keys($this->valueColumns());

        return array_combine($headers, array_map('strtolower', $headers));
    }

    private function query(): Builder
    {
        return $this->model()::query();
    }

    /**
     * The existing record for a validated row (community id resolved). Looked up by whereDate so the match
     * doesn't depend on how the driver stores the date column.
     *
     * @param  array<string, mixed>  $row
     */
    private function find(array $row): ?Model
    {
        return $this->query()->where('community_id', $row['COMMUNITY_ID'])->whereDate('assessment_date', $row['ASSESSMENT_DATE'])->first();
    }
}
