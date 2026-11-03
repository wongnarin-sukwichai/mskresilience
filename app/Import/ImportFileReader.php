<?php

namespace App\Import;

use App\Import\Sheets\ImportSheet;
use App\Models\ImportBatch;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
        $disk = Storage::disk('local');
        if ($batch->isFileDeleted()) {
            return ['fileDeleted' => true] + $result;
        }
        if (! $disk->exists($batch->stored_path)) {
            return ['missing' => true] + $result;
        }

        try {
            $grid = IOFactory::load($disk->path($batch->stored_path))->getSheet(0)->toArray(null, true, false, false);
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
                if (($rules[$name] ?? null) === ImportSheet::DATE && is_numeric($cells[$pos])) {
                    $cells[$pos] = Date::excelToDateTimeObject((float) $cells[$pos])->format('Y-m-d');
                }
            }

            if ($batch->isCancelled()) {
                [$status, $by] = ['cancelled', null];
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
                ImportSheet::COMMUNITY => strtoupper(trim((string) $value)),
                default => trim((string) $value),
            };
        }, $sheet->keyColumns()));
    }
}
