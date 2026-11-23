<?php

namespace App\Import;

use App\Models\ImportBatch;

/**
 * Audit-trail entries for import actions (TOR 4.1.1(2)-4, คุณลักษณะ 2.1-6). The imported rows aren't logged one by
 * one: the batch is the unit (who, when, which file, how many rows), and the kept file plus the replaced-row
 * snapshots (import_replaced_rows) already hold every value before and after.
 */
class ImportAudit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function log(ImportBatch $batch, string $event, string $description, array $details = []): void
    {
        activity('imports')
            ->performedOn($batch)
            ->event($event)
            ->withProperties(['details' => ['work_group' => $batch->work_group->label(), ...$details]])
            ->log($description);
    }
}
