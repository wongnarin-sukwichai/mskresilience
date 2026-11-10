<?php

namespace App\Import\Sheets;

use App\Models\ImportBatch;

/**
 * One กลุ่มงาน's importer. ImportValidator does the generic work (header check, blanks, types, community codes,
 * duplicates); a sheet only declares each column's rule and how validated rows are saved. See ImportSheets for
 * which groups are importable so far (built sheet by sheet).
 */
interface ImportSheet
{
    public const DATE = 'date';

    public const COMMUNITY = 'community';

    public const COMMUNITY_NAME = 'community_name';

    /** Whole number ≥ 0. */
    public const COUNT = 'count';

    /** Whole number 0–5. */
    public const SCORE = 'score';

    /** Number ≥ 0 with at most 2 decimal places (owner, 2026-11-03), e.g. area, rate per 1,000, hours, baht. */
    public const DECIMAL = 'decimal';

    /** Number 0–100 with at most 2 decimal places (ร้อยละ). */
    public const PERCENT = 'percent';

    /** Free text, trimmed, at most 255 characters. */
    public const TEXT = 'text';

    /** Free text, trimmed, at most 2000 characters (e.g. EVENT_DESCRIPTION). */
    public const LONG_TEXT = 'long_text';

    /** A date that may be left blank (e.g. EVENT_END of an event still going on). */
    public const OPTIONAL_DATE = 'optional_date';

    /** Staff-typed event code: EV + 4 digits (owner decision 2026-11-02). Required. */
    public const EVENT_ID = 'event_id';

    /** One hazard: FLOOD, FIRE, STORM, HEAT or PM2.5 (stored as the internal key, PM25). Required. */
    public const HAZARD = 'hazard';

    /** Zero or more hazards, separated by commas or spaces. Stored as keys joined by ",". */
    public const HAZARD_LIST = 'hazard_list';

    /** ใช่ / ไม่ใช่ (also Y/N, Yes/No, TRUE/FALSE, 1/0). Stored as a boolean. */
    public const YES_NO = 'yes_no';

    /** Rules where a blank cell is an error rather than ไม่มีข้อมูล. */
    public const REQUIRED = [self::DATE, self::COMMUNITY, self::EVENT_ID, self::HAZARD];

    /**
     * Rule per header. The keys must be exactly ImportColumns::for() of this group, in the same order.
     *
     * @return array<string, string>
     */
    public function rules(): array;

    /**
     * Headers that identify a row. Two rows in one file with the same key are an error, and a row whose key
     * is already in the DB replaces it.
     *
     * @return list<string>
     */
    public function keyColumns(): array;

    /**
     * Whether this sheet's table has data for the community, so the community can't be deleted
     * (CommunityManagementController::destroy asks every sheet).
     */
    public function referencesCommunity(int $communityId): bool;

    /**
     * The distinct dates (Y-m-d) of the records now in this sheet's table: ASSESSMENT_DATE, or EVENT_START for
     * events. App\Scoring\CriteriaSets maps them to fiscal years to know which criteria sets are in use.
     *
     * @return list<string>
     */
    public function recordDates(): array;

    /**
     * Checks across rows or against data already in the system, run by ImportValidator only when the file has no
     * other errors. Each one becomes a line in the step-by-step list, and its warnings/errors join the file's.
     * Warnings never block (e.g. KPI above its group, owner decision 2026-11-03); errors do (e.g. an EVENT_ID
     * already used by another community).
     *
     * @param  list<array{row: int, values: array<string, mixed>}>  $rows  validated rows (COMMUNITY_ID is the code)
     * @return list<array{label: string, status: 'ok'|'warning'|'error', detail: string, warnings: list<array{row: int, column: string|null, message: string}>, errors?: list<array{row: int, column: string|null, message: string}>}>
     */
    public function crossChecks(array $rows): array;

    /**
     * Which of these validated rows would replace existing data: the count, plus the earlier imports that data
     * came from (for the confirm dialog's replace warning), newest first.
     *
     * @param  list<array<string, mixed>>  $rows  normalized values keyed by header (community id resolved)
     * @return array{count: int, batches: list<array{createdAt: string|null, user: string|null, rows: int}>}
     */
    public function existing(array $rows): array;

    /**
     * Save validated rows, replacing existing rows with the same key. Before a row is replaced, its old values
     * must be kept in $batch->replacedRows(), so cancel() can put them back. Called inside a DB transaction.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function save(array $rows, ImportBatch $batch): void;

    /**
     * Undo a batch's save(): rows it added are deleted, and rows it replaced get their kept values back.
     * Called inside a DB transaction, and only when no later active batch replaced any of its rows.
     */
    public function cancel(ImportBatch $batch): void;

    /**
     * For the "ดูข้อมูล" viewer (ImportFileReader), on an active batch: its rows keyed by the key columns' values
     * joined by "|" (community code, dates as Y-m-d), in keyColumns() order. Status is 'active' (the data is
     * this batch's) or 'replaced', with `by` = the later active batch that overwrote it.
     *
     * @return array<string, array{status: string, by?: int}>
     */
    public function rowStatuses(ImportBatch $batch): array;
}
