<?php

namespace App\Import\Sheets;

use App\Models\ImportBatch;
use App\Models\ImportReplacedRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A sheet whose rows each go into one record of its own table, identified by the sheet's key columns. It does the
 * shared work: replace-by-key with old values kept in import_replaced_rows, cancel() restoring them,
 * rowStatuses() for the viewer, and referencesCommunity(). Each value column is stored in the table column with
 * the lower-cased header name (V_ELDERLY_COUNT → v_elderly_count).
 *
 * Two shapes so far: CommunityDateSheet (community + ASSESSMENT_DATE, 7 sheets) and EventSheet (EVENT_ID).
 */
abstract class TableSheet implements ImportSheet
{
    /**
     * The table's model. It must have community_id, import_batch_id, the value columns, and a community() relation.
     *
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    /**
     * Header → rule for the columns stored as-is (lower-cased name), in template order. The key columns that
     * are stored in other ways (community_id, …) aren't included.
     *
     * @return array<string, string>
     */
    abstract protected function valueColumns(): array;

    /**
     * The existing record for a validated row (community id resolved), or null if it is new.
     *
     * @param  array<string, mixed>  $row
     */
    abstract protected function find(array $row): ?Model;

    /**
     * The key attributes of a new record for a validated row (community id resolved).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    abstract protected function newRecordAttributes(array $row): array;

    /**
     * A record's key in rowStatuses() format: the key columns' values joined by "|", as ImportFileReader builds
     * them from the file (community code, dates as Y-m-d). `community` is loaded.
     */
    abstract protected function recordKey(Model $record): string;

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
                    // Raw DB values, not only(): casts would turn a date into a Carbon ISO string that a date
                    // column won't take back on cancel.
                    'previous' => array_intersect_key($record->getAttributes(), array_flip($columns)),
                ]);
            } else {
                $model = $this->model();
                $record = (new $model)->forceFill($this->newRecordAttributes($row));
            }
            $record->forceFill($values)->save();
        }
    }

    public function rowStatuses(ImportBatch $batch): array
    {
        $statuses = [];

        $replaced = ImportReplacedRow::where('previous_batch_id', $batch->id)
            ->whereHas('batch', fn ($q) => $q->whereNull('cancelled_at'))->get(['import_batch_id', 'record_id']);
        $records = $this->query()->with('community:id,code')
            ->where(fn ($q) => $q->where('import_batch_id', $batch->id)->orWhereIn('id', $replaced->pluck('record_id')))
            ->get()->keyBy('id');

        // The first later batch that overwrote a row is the one named, even if another one replaced it again since.
        foreach ($replaced->sortBy('import_batch_id') as $r) {
            if ($record = $records->get($r->record_id)) {
                $statuses[$this->recordKey($record)] ??= ['status' => 'replaced', 'by' => $r->import_batch_id];
            }
        }
        foreach ($records->where('import_batch_id', $batch->id) as $record) {
            $statuses[$this->recordKey($record)] = ['status' => 'active'];
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

    public function recordDates(): array
    {
        return $this->query()->distinct()->pluck($this->dateColumn())
            ->map(fn ($date) => substr((string) ($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date), 0, 10))
            ->unique()->values()->all();
    }

    /**
     * The table column holding the record's date, for recordDates().
     */
    protected function dateColumn(): string
    {
        return 'assessment_date';
    }

    protected function query(): Builder
    {
        return $this->model()::query();
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
}
