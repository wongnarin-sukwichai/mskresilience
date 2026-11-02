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
     * Which of these validated rows would replace existing data: the count, plus the earlier imports that data
     * came from (for the confirm dialog's replace warning), newest first.
     *
     * @param  list<array<string, mixed>>  $rows  normalized values keyed by header (community id resolved)
     * @return array{count: int, batches: list<array{createdAt: string|null, user: string|null, rows: int}>}
     */
    public function existing(array $rows): array;

    /**
     * Save validated rows, replacing existing rows with the same key. Called inside a DB transaction.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function save(array $rows, ImportBatch $batch): void;
}
