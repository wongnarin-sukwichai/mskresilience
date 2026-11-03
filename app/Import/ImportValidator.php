<?php

namespace App\Import;

use App\Import\Sheets\ImportSheet;
use App\Models\Community;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Throwable;

/**
 * Reads an uploaded import file and checks it against the group's ImportSheet rules, without saving anything
 * (import workflow agreed with the owner 2026-10-29 / 2026-11-02):
 * - Only the first sheet is read. Headers are matched by name after trimming (PM2.5 = PM25). A missing column is
 *   an error. An extra column is a warning and is ignored.
 * - A blank cell, "-" or "n/a" becomes null (ไม่มีข้อมูล), never 0. A blank key field is an error.
 * - COMMUNITY_ID must be a code in `communities`. COMMUNITY_NAME is optional, and a mismatch is only a warning.
 * - Two rows with the same key (e.g. community + date) are an error. A key already in the DB will be replaced.
 * - Not all 32 communities are required. The summary lists the included and missing ones.
 *
 * The result is a plain array, sent to the page as the preview and re-computed on confirm.
 */
class ImportValidator
{
    private const BLANK_MARKERS = ['', '-', 'n/a'];

    /** Internal hazard keys (PM2.5 is PM25, as everywhere else in the code). */
    private const HAZARDS = ['FLOOD', 'FIRE', 'STORM', 'HEAT', 'PM25'];

    /** @var array<string, Community> code → community */
    private array $communities;

    /** @var list<array{row: int|null, column: string|null, message: string}> */
    private array $errors = [];

    /** @var list<array{row: int|null, column: string|null, message: string}> */
    private array $warnings = [];

    /** @var list<array{name: string, rule: string, found: bool, errors: int, blanks: int, warnings: int}> per expected column, in template order */
    private array $columns = [];

    /** How far validation got: 'read' (file unreadable), 'header' (header errors), or 'rows' (fully checked). */
    private string $stage = 'read';

    private int $duplicateCount = 0;

    /** @var list<array{label: string, status: string, detail: string, warnings: list<array<string, mixed>>}> from ImportSheet::crossChecks() */
    private array $crossChecks = [];

    public function __construct(private ImportSheet $sheet)
    {
        $this->communities = Community::orderBy('code')->get()->keyBy('code')->all();
    }

    /**
     * @return array{
     *     columns: list<array{name: string, found: bool, errors: int, blanks: int}>,
     *     extraColumns: list<string>,
     *     rows: list<array{row: int, values: array<string, mixed>}>,
     *     errors: list<array{row: int|null, column: string|null, message: string}>,
     *     warnings: list<array{row: int|null, column: string|null, message: string}>,
     *     summary: array{rowCount: int, blankCount: int, replaceCount: int, included: list<string>, missing: list<string>},
     *     saveRows: list<array<string, mixed>>,
     * }
     *
     * `saveRows` (community id resolved) is for ImportSheet::save() only. Don't send it to the page.
     */
    public function validate(string $path): array
    {
        $rules = $this->sheet->rules();
        $this->columns = array_map(fn ($name, $rule) => ['name' => $name, 'rule' => $rule, 'found' => false, 'errors' => 0, 'blanks' => 0, 'warnings' => 0], array_keys($rules), $rules);
        $columnIndex = array_flip(array_keys($rules));
        $columns = &$this->columns;

        try {
            $grid = IOFactory::load($path)->getSheet(0)->toArray(null, true, false, false);
        } catch (Throwable) {
            $this->error(null, null, 'อ่านไฟล์ไม่ได้ กรุณาตรวจสอบว่าเป็นไฟล์ Excel (.xlsx หรือ .xls) ที่ถูกต้อง');

            return $this->result([], [], []);
        }
        $this->stage = 'header';

        // Header row: map each expected header to its position in the file.
        $positions = [];
        $extra = [];
        foreach ($grid[0] ?? [] as $pos => $raw) {
            $name = $this->normalizeHeader($raw);
            if ($name === '') {
                continue;
            }
            if (! isset($rules[$name])) {
                $extra[] = $name;
            } elseif (isset($positions[$name])) {
                $this->error(1, $name, "คอลัมน์ {$name} ซ้ำกันในแถวหัวตาราง");
            } else {
                $positions[$name] = $pos;
                $columns[$columnIndex[$name]]['found'] = true;
            }
        }
        foreach ($rules as $name => $rule) {
            // COMMUNITY_NAME is only a readable helper, so a file without that column is fine.
            if (! isset($positions[$name]) && $rule !== ImportSheet::COMMUNITY_NAME) {
                $this->error(1, $name, "ไม่พบคอลัมน์ {$name}");
            }
        }
        if ($extra) {
            $this->warning(1, null, 'มีคอลัมน์ที่ระบบไม่ใช้ ('.implode(', ', $extra).') ระบบจะข้ามคอลัมน์เหล่านี้');
        }
        if ($this->errors) {
            return $this->result([], $extra, []);
        }
        $this->stage = 'rows';

        $rows = [];
        $saveRows = [];
        $seenKeys = [];
        foreach (array_slice($grid, 1, null, true) as $i => $cells) {
            $rowNumber = $i + 1;
            $raw = [];
            foreach ($positions as $name => $pos) {
                $raw[$name] = $this->blankToNull($cells[$pos] ?? null);
            }
            if (count(array_filter($raw, fn ($v) => $v !== null)) === 0) {
                continue; // fully empty row
            }

            $values = [];
            $rowOk = true;
            foreach ($rules as $name => $rule) {
                $value = $raw[$name] ?? null;
                $col = &$columns[$columnIndex[$name]];
                if ($value === null) {
                    if (in_array($rule, ImportSheet::REQUIRED, true)) {
                        $this->error($rowNumber, $name, "ต้องกรอก {$name}");
                        $col['errors']++;
                        $rowOk = false;
                    } elseif ($rule !== ImportSheet::COMMUNITY_NAME) {
                        $col['blanks']++;
                    }
                    $values[$name] = null;

                    continue;
                }

                [$parsed, $message] = $this->parse($rule, $value);
                if ($message !== null) {
                    $this->error($rowNumber, $name, $message);
                    $col['errors']++;
                    $rowOk = false;
                }
                $values[$name] = $parsed;
            }
            unset($col);

            $community = $this->communities[$values['COMMUNITY_ID'] ?? ''] ?? null;
            if ($community && isset($values['COMMUNITY_NAME']) && $this->normalizeName($values['COMMUNITY_NAME']) !== $this->normalizeName($community->name)) {
                $this->warning($rowNumber, 'COMMUNITY_NAME', "ชื่อชุมชน \"{$values['COMMUNITY_NAME']}\" ไม่ตรงกับรหัส {$community->code} (ในระบบคือ {$community->name}) ระบบจะใช้ตามรหัส");
                $columns[$columnIndex['COMMUNITY_NAME']]['warnings']++;
            }

            if ($rowOk) {
                $key = implode('|', array_map(fn ($k) => $values[$k], $this->sheet->keyColumns()));
                if (isset($seenKeys[$key])) {
                    $this->error($rowNumber, null, "ข้อมูลซ้ำกับแถวที่ {$seenKeys[$key]} (".implode(' + ', $this->sheet->keyColumns()).' เดียวกัน)');
                    $this->duplicateCount++;
                    $rowOk = false;
                } else {
                    $seenKeys[$key] = $rowNumber;
                }
            }

            $rows[] = ['row' => $rowNumber, 'values' => $values];
            if ($rowOk) {
                $saveRows[] = [...$values, 'COMMUNITY_ID' => $community->id];
            }
        }

        if (! $rows) {
            $this->error(null, null, 'ไม่พบข้อมูลในไฟล์ (มีแต่แถวหัวตาราง)');
        }
        $blanks = array_sum(array_column($columns, 'blanks'));
        if ($blanks) {
            $this->warning(null, null, "มีช่องว่าง {$blanks} ช่อง ระบบจะบันทึกเป็น \"ไม่มีข้อมูล\" (ไม่ใช่ 0)");
        }

        // Cross checks (e.g. KPI vs its group, EVENT_ID vs events in the system) only make sense on a file
        // without errors. KPI only warns; an event clash is an error, which also empties saveRows below.
        if ($rows && ! $this->errors) {
            $this->crossChecks = $this->sheet->crossChecks($rows);
            foreach ($this->crossChecks as $cross) {
                foreach ($cross['warnings'] as $w) {
                    $this->warning($w['row'], $w['column'], $w['message']);
                }
                foreach ($cross['errors'] ?? [] as $e) {
                    $this->error($e['row'], $e['column'], $e['message']);
                }
            }
        }

        return $this->result($rows, $extra, $this->errors ? [] : $saveRows);
    }

    /**
     * @param  list<array{row: int, values: array<string, mixed>}>  $rows
     * @param  list<string>  $extra
     * @param  list<array<string, mixed>>  $saveRows
     */
    private function result(array $rows, array $extra, array $saveRows): array
    {
        $included = collect($rows)->pluck('values.COMMUNITY_ID')->filter()->unique()->sort()->values()->all();
        $existing = $saveRows ? $this->sheet->existing($saveRows) : ['count' => 0, 'batches' => []];

        return [
            'checks' => $this->checks($rows, $extra, $existing),
            // Validation stopped early (unreadable file, header errors, no data rows), so the list is short.
            'fatal' => $this->stage !== 'rows' || ! $rows,
            'columns' => $this->columns,
            'extraColumns' => $extra,
            'rows' => $rows,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'summary' => [
                'rowCount' => count($rows),
                'blankCount' => array_sum(array_column($this->columns, 'blanks')),
                'replaceCount' => $existing['count'],
                'replacedBatches' => $existing['batches'],
                'included' => $included,
                'missing' => array_values(array_diff(array_keys($this->communities), $included)),
            ],
            'saveRows' => $saveRows,
        ];
    }

    /**
     * The step-by-step checklist the page reveals one line at a time (owner request 2026-11-02). Every line is
     * a check that really ran, with its real outcome. Never add a line that isn't backed by a check. A fatal
     * stage (unreadable file, header errors) ends the list there.
     *
     * @param  list<array{row: int, values: array<string, mixed>}>  $rows
     * @param  list<string>  $extra
     * @param  array{count: int, batches: list<array<string, mixed>>}  $existing
     * @return list<array{label: string, status: 'ok'|'warning'|'error'|'skipped', detail: string}>
     */
    private function checks(array $rows, array $extra, array $existing): array
    {
        $check = fn (string $label, string $status, string $detail) => compact('label', 'status', 'detail');

        if ($this->stage === 'read') {
            return [$check('อ่านไฟล์', 'error', $this->errors[0]['message'])];
        }
        $checks = [$check('อ่านไฟล์', 'ok', $this->stage === 'rows' ? 'พบข้อมูล '.count($rows).' แถว' : 'อ่านไฟล์ได้')];

        $headerErrors = array_filter($this->errors, fn ($e) => $e['row'] === 1);
        $found = count(array_filter($this->columns, fn ($c) => $c['found']));
        $checks[] = match (true) {
            (bool) $headerErrors => $check('ตรวจหัวคอลัมน์', 'error', implode(', ', array_column($headerErrors, 'message'))),
            (bool) $extra => $check('ตรวจหัวคอลัมน์', 'warning', "ครบ {$found} คอลัมน์ มีคอลัมน์ที่ระบบไม่ใช้ ".count($extra).' คอลัมน์ (จะข้าม)'),
            default => $check('ตรวจหัวคอลัมน์', 'ok', "ครบ {$found} คอลัมน์"),
        };
        if ($this->stage !== 'rows') {
            return $checks;
        }
        if (! $rows) {
            $checks[] = $check('ตรวจข้อมูล', 'error', 'ไม่พบข้อมูลในไฟล์ (มีแต่แถวหัวตาราง)');

            return $checks;
        }

        $n = count($rows);
        foreach ($this->columns as $col) {
            $checks[] = match (true) {
                $col['rule'] === ImportSheet::COMMUNITY_NAME && ! $col['found'] => $check("ตรวจชื่อชุมชนเทียบกับรหัส ({$col['name']})", 'ok', 'ไม่มีคอลัมน์นี้ (ไม่บังคับ)'),
                $col['rule'] === ImportSheet::COMMUNITY_NAME => $col['warnings']
                    ? $check("ตรวจชื่อชุมชนเทียบกับรหัส ({$col['name']})", 'warning', "ไม่ตรงกับรหัส {$col['warnings']} แถว (ระบบจะใช้ตามรหัส)")
                    : $check("ตรวจชื่อชุมชนเทียบกับรหัส ({$col['name']})", 'ok', 'ตรงกับรหัสทุกแถวที่กรอก'),
                $col['errors'] > 0 => $check("ตรวจ {$col['name']}", 'error', "ผิด {$col['errors']} ช่อง"),
                $col['blanks'] > 0 => $check("ตรวจ {$col['name']}", 'warning', "ถูกต้อง ว่าง {$col['blanks']} ช่อง (บันทึกเป็นไม่มีข้อมูล)"),
                default => $check("ตรวจ {$col['name']}", 'ok', "ถูกต้องทั้ง {$n} แถว"),
            };
        }

        $keyLabel = implode(' + ', $this->sheet->keyColumns());
        $checks[] = $this->duplicateCount
            ? $check("ตรวจข้อมูลซ้ำ ({$keyLabel})", 'error', "ซ้ำ {$this->duplicateCount} แถว")
            : $check("ตรวจข้อมูลซ้ำ ({$keyLabel})", 'ok', 'ไม่พบข้อมูลซ้ำ');

        foreach ($this->crossChecks as $cross) {
            $checks[] = $check($cross['label'], $cross['status'], $cross['detail']);
        }

        $checks[] = match (true) {
            (bool) $this->errors => $check('เทียบกับข้อมูลในระบบ', 'skipped', 'ข้าม เพราะไฟล์ยังมีข้อผิดพลาด'),
            $existing['count'] > 0 => $check('เทียบกับข้อมูลในระบบ', 'warning', "จะแทนที่ข้อมูลเดิม {$existing['count']} แถว"),
            default => $check('เทียบกับข้อมูลในระบบ', 'ok', 'เป็นข้อมูลใหม่ทั้งหมด ไม่มีการแทนที่'),
        };

        return $checks;
    }

    /**
     * @return array{0: mixed, 1: string|null} [parsed value, error message]
     */
    private function parse(string $rule, mixed $value): array
    {
        return match ($rule) {
            ImportSheet::DATE => $this->parseDate($value),
            ImportSheet::COMMUNITY => $this->parseCommunity($value),
            ImportSheet::COMMUNITY_NAME => [trim((string) $value), null],
            ImportSheet::COUNT => $this->parseWhole($value, 0, null, 'ต้องเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป'),
            ImportSheet::SCORE => $this->parseWhole($value, 0, 5, 'ต้องเป็นจำนวนเต็ม 0–5'),
            ImportSheet::DECIMAL => $this->parseDecimal($value, null, 'ต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป'),
            ImportSheet::PERCENT => $this->parseDecimal($value, 100, 'ต้องเป็นตัวเลข 0–100 (ร้อยละ)'),
            ImportSheet::TEXT => mb_strlen($text = trim((string) $value)) > 255
                ? [null, 'ข้อความยาวเกิน 255 ตัวอักษร']
                : [$text, null],
            ImportSheet::LONG_TEXT => mb_strlen($text = trim((string) $value)) > 2000
                ? [null, 'ข้อความยาวเกิน 2,000 ตัวอักษร']
                : [$text, null],
            ImportSheet::OPTIONAL_DATE => self::parseDate($value),
            ImportSheet::EVENT_ID => preg_match('/^EV\d{4}$/', $code = strtoupper(trim((string) $value)))
                ? [$code, null]
                : [null, "\"{$value}\" ไม่ใช่รหัสเหตุการณ์ ต้องเป็น EV ตามด้วยตัวเลข 4 หลัก เช่น EV0001"],
            ImportSheet::HAZARD => ($key = self::hazardKey($value))
                ? [$key, null]
                : [null, "\"{$value}\" ไม่ใช่ภัยที่ระบบรู้จัก (FLOOD, FIRE, STORM, HEAT, PM2.5)"],
            ImportSheet::HAZARD_LIST => $this->parseHazardList($value),
            ImportSheet::YES_NO => match (mb_strtolower(trim((string) $value))) {
                'ใช่', 'y', 'yes', 'true', '1' => [true, null],
                'ไม่ใช่', 'n', 'no', 'false', '0' => [false, null],
                default => [null, "\"{$value}\" ต้องเป็น ใช่ หรือ ไม่ใช่"],
            },
        };
    }

    /**
     * A hazard as typed (any case, PM2.5 or PM25) → its internal key, or null.
     */
    private static function hazardKey(mixed $value): ?string
    {
        $key = str_replace('PM2.5', 'PM25', strtoupper(trim((string) $value)));

        return in_array($key, self::HAZARDS, true) ? $key : null;
    }

    /**
     * @return array{0: string|null, 1: string|null} keys joined by ",", without repeats
     */
    private function parseHazardList(mixed $value): array
    {
        $keys = [];
        foreach (preg_split('/[\s,;\/]+/u', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (! $key = self::hazardKey($part)) {
                return [null, "\"{$part}\" ไม่ใช่ภัยที่ระบบรู้จัก (FLOOD, FIRE, STORM, HEAT, PM2.5) คั่นหลายภัยด้วยเครื่องหมายจุลภาค"];
            }
            $keys[$key] = true;
        }

        return [implode(',', array_keys($keys)), null];
    }

    /**
     * A number ≥ 0 (and ≤ $max) with at most 2 decimal places. A float like 0.1 + 0.2 from Excel still counts as
     * 2 places, so the check allows a tiny rounding error and the value is stored rounded.
     *
     * @return array{0: float|null, 1: string|null}
     */
    private function parseDecimal(mixed $value, ?int $max, string $message): array
    {
        $number = is_string($value) ? str_replace(',', '', trim($value)) : $value;
        if (! is_numeric($number)) {
            return [null, "\"{$value}\" {$message}"];
        }
        $number = (float) $number;
        if ($number < 0 || ($max !== null && $number > $max)) {
            return [null, "{$number} {$message}"];
        }
        if (abs(round($number, 2) - $number) > 1e-9) {
            return [null, "{$number} มีทศนิยมเกิน 2 ตำแหน่ง"];
        }

        return [round($number, 2), null];
    }

    /**
     * Also used by ImportFileReader to build row keys, so it's public and static.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function parseDate(mixed $value): array
    {
        $date = null;
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $date = CarbonImmutable::instance(Date::excelToDateTimeObject((float) $value));
        } else {
            foreach (['d/m/Y', 'j/n/Y', 'Y-m-d'] as $format) {
                try {
                    $date = CarbonImmutable::createFromFormat("!{$format}", trim((string) $value));
                    break;
                } catch (Throwable) {
                }
            }
        }

        if (! $date) {
            return [null, "\"{$value}\" ไม่ใช่วันที่ กรุณากรอกเป็นวันที่ เช่น 20/01/2025"];
        }
        if ($date->year > 2400) {
            return [null, "ปี {$date->year} ดูเหมือนเป็นปี พ.ศ. กรุณากรอกปี ค.ศ."];
        }
        if ($date->year < 2000 || $date->year > 2100) {
            return [null, "ปี {$date->year} ไม่ถูกต้อง"];
        }

        return [$date->toDateString(), null];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseCommunity(mixed $value): array
    {
        $code = strtoupper(trim((string) $value));

        return isset($this->communities[$code])
            ? [$code, null]
            : [null, "ไม่พบรหัสชุมชน \"{$value}\" ในระบบ"];
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function parseWhole(mixed $value, int $min, ?int $max, string $message): array
    {
        $number = is_string($value) ? str_replace(',', '', trim($value)) : $value;
        if (! is_numeric($number) || floor((float) $number) != (float) $number) {
            return [null, "\"{$value}\" {$message}"];
        }
        $number = (int) $number;
        if ($number < $min || ($max !== null && $number > $max)) {
            return [null, "{$number} {$message}"];
        }

        return [$number, null];
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && in_array(mb_strtolower(trim($value)), self::BLANK_MARKERS, true)) {
            return null;
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function normalizeHeader(mixed $raw): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $raw));

        return str_replace('PM2.5', 'PM25', $name);
    }

    private function normalizeName(string $name): string
    {
        return preg_replace('/\s+/u', '', preg_replace('/^ชุมชน/u', '', trim($name)));
    }

    private function error(?int $row, ?string $column, string $message): void
    {
        $this->errors[] = compact('row', 'column', 'message');
    }

    private function warning(?int $row, ?string $column, string $message): void
    {
        $this->warnings[] = compact('row', 'column', 'message');
    }
}
