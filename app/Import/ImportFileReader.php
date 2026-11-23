<?php

namespace App\Import;

use App\Import\Sheets\ImportSheet;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * The "ดูข้อมูล" viewer in the history menus (owner decision 2026-11-03, instead of downloading the file): reads
 * a batch's kept original file back as-is, so staff can check what was imported and whether to re-import.
 *
 * It deliberately does NOT re-validate. The rules may change later, and an old file that was fine when imported
 * must not suddenly show errors. Values are shown as they are in the file. The only change is that cells in date
 * columns that are Excel serials are shown as Y-m-d. Each row also gets its current status from
 * ImportSheet::rowStatuses(): still in use, replaced by a later import, or cancelled.
 */
class ImportFileReader
{
    /** Rows sent to the page at most. A batch past this shows a "first N rows" note. */
    public const MAX_ROWS = 1000;

    /**
     * @return array{
     *     fileDeleted: bool,
     *     missing: bool,
     *     unreadable: bool,
     *     headers: list<array{name: string, used: bool}>,
     *     rows: list<array{row: int, cells: list<mixed>, status: string, by: int|null}>,
     *     total: int,
     *     counts: array<string, int>,
     * }
     */
    public static function read(ImportBatch $batch): array
    {
        $result = ['fileDeleted' => false, 'missing' => false, 'unreadable' => false, 'headers' => [], 'rows' => [], 'total' => 0, 'counts' => []];
        if ($batch->isWeb()) {
            return self::fromPayload($batch, $result);
        }
        $disk = Storage::disk('local');
        if ($batch->isFileDeleted()) {
            return ['fileDeleted' => true] + $result;
        }
        if (! $disk->exists($batch->stored_path)) {
            return ['missing' => true] + $result;
        }

        try {
            $grid = SpreadsheetGrid::load($disk->path($batch->stored_path));
        } catch (Throwable) {
            return ['unreadable' => true] + $result;
        }

        $sheet = ImportSheets::for($batch->work_group);
        $rules = $sheet?->rules() ?? [];
        $names = array_map(fn ($raw) => str_replace('PM2.5', 'PM25', trim(preg_replace('/\s+/u', ' ', (string) $raw))), $grid[0] ?? []);
        // Drop trailing blank header cells (Excel often reports a few empty columns past the data).
        while ($names && end($names) === '') {
            array_pop($names);
        }
        $result['headers'] = array_map(fn ($name) => ['name' => $name, 'used' => isset($rules[$name])], $names);
        $positions = array_flip(array_filter($names, fn ($n) => $n !== ''));

        $statuses = $batch->isCancelled() || ! $sheet ? [] : $sheet->rowStatuses($batch);

        foreach (array_slice($grid, 1, null, true) as $i => $cells) {
            $cells = array_slice(array_pad($cells, count($names), null), 0, count($names));
            if (count(array_filter($cells, fn ($v) => $v !== null && trim((string) $v) !== '')) === 0) {
                continue;
            }
            foreach ($names as $pos => $name) {
                if (in_array($rules[$name] ?? null, [ImportSheet::DATE, ImportSheet::OPTIONAL_DATE], true) && is_numeric($cells[$pos])) {
                    $cells[$pos] = Date::excelToDateTimeObject((float) $cells[$pos])->format('Y-m-d');
                }
            }

            if ($batch->isCancelled()) {
                [$status, $by] = ['cancelled', null];
            } elseif (! $batch->isApplied()) {
                [$status, $by] = [$batch->status, null]; // pending / rejected: nothing saved
            } else {
                $key = self::key($sheet, $positions, $cells);
                [$status, $by] = isset($statuses[$key]) ? [$statuses[$key]['status'], $statuses[$key]['by'] ?? null] : ['unknown', null];
            }

            $result['total']++;
            $result['counts'][$status] = ($result['counts'][$status] ?? 0) + 1;
            if (count($result['rows']) < self::MAX_ROWS) {
                $result['rows'][] = ['row' => $i + 1, 'cells' => $cells, 'status' => $status, 'by' => $by];
            }
        }

        return $result;
    }

    /**
     * A web-form change has no file: show its row from the payload, with the values before the change on an extra
     * row ('previous') for an edit, and 'deleted' for a delete that went through.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function fromPayload(ImportBatch $batch, array $result): array
    {
        $sheet = ImportSheets::for($batch->work_group);
        $headers = array_keys($sheet?->rules() ?? []);
        $result['headers'] = array_map(fn ($name) => ['name' => $name, 'used' => true], $headers);
        $cells = fn (array $row) => array_map(fn ($h) => isset($row[$h]) && $row[$h] !== '' ? $row[$h] : null, $headers);
        $add = function (array $row, string $status, ?int $by = null) use (&$result, $cells) {
            $result['rows'][] = ['row' => count($result['rows']) + 1, 'cells' => $cells($row), 'status' => $status, 'by' => $by];
            $result['total']++;
            $result['counts'][$status] = ($result['counts'][$status] ?? 0) + 1;
        };

        $payload = $batch->payload ?? [];
        if ($batch->source === 'web_edit' && ! empty($payload['before'])) {
            $add($payload['before'], 'previous');
        }
        $row = $payload['row'] ?? [];
        $status = match (true) {
            $batch->isCancelled() => 'cancelled',
            ! $batch->isApplied() => $batch->status,
            $batch->source === 'web_delete' => 'deleted',
            default => null,
        };
        if ($status === null) {
            $positions = array_flip($headers);
            $statuses = $sheet ? $sheet->rowStatuses($batch) : [];
            $key = self::key($sheet, $positions, $cells($row));
            [$status, $by] = isset($statuses[$key]) ? [$statuses[$key]['status'], $statuses[$key]['by'] ?? null] : ['unknown', null];
            $add($row, $status, $by);
        } else {
            $add($row, $status);
        }

        return $result;
    }

    /**
     * The row's key in ImportSheet::rowStatuses() format: the key columns' values joined by "|", normalized the
     * way ImportValidator saved them (community code upper-cased, dates as Y-m-d).
     *
     * @param  array<string, int>  $positions
     * @param  list<mixed>  $cells
     */
    private static function key(?ImportSheet $sheet, array $positions, array $cells): ?string
    {
        if (! $sheet) {
            return null;
        }
        $rules = $sheet->rules();

        return implode('|', array_map(function ($column) use ($rules, $positions, $cells) {
            $value = isset($positions[$column]) ? $cells[$positions[$column]] : null;

            return match ($rules[$column]) {
                ImportSheet::DATE => ImportValidator::parseDate($value)[0],
                ImportSheet::COMMUNITY, ImportSheet::EVENT_ID => strtoupper(trim((string) $value)),
                default => trim((string) $value),
            };
        }, $sheet->keyColumns()));
    }
}
