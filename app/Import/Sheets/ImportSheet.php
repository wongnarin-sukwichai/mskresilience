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
     * Checks against other sheets' data, run by ImportValidator only when the file has no errors. Each one becomes
     * a line in the step-by-step list, and its warnings join the file's warnings. They never block the import
     * (e.g. KPI above its group, owner decision 2026-11-03).
     *
     * @param  list<array{row: int, values: array<string, mixed>}>  $rows  validated rows (COMMUNITY_ID is the code)
     * @return list<array{label: string, status: 'ok'|'warning', detail: string, warnings: list<array{row: int, column: string, message: string}>}>
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
