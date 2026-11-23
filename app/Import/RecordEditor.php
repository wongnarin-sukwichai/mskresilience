<?php

namespace App\Import;

use App\Enums\WorkGroup;
use App\Import\Sheets\EventSheet;
use App\Import\Sheets\ImportSheet;
use App\Import\Sheets\TableSheet;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The web edit form (แก้ไขข้อมูล, TOR 4.1.1(6)-2: Data Owner "แก้ไขข้อมูลในขอบเขตที่ได้รับอนุญาต"). Each add, edit
 * or delete of one row becomes its own import batch (source web_add / web_edit / web_delete) with the row kept in
 * payload, so it shows in the import history, can be cancelled like an import (the old values come back), goes to
 * the audit trail, and waits for an admin when approval mode is on.
 *
 * A typed value goes through ImportValidator exactly like a value in a file: the form only builds a one-row grid.
 */
class RecordEditor
{
    /**
     * Headers that can't change when editing a row: its key, the community, and for an event its start date (the
     * event importer treats those as the event's identity). A wrong key is fixed by deleting the row and adding it
     * again.
     *
     * @return list<string>
     */
    public static function lockedOnEdit(ImportSheet $sheet): array
    {
        return array_values(array_unique([
            ...$sheet->keyColumns(),
            'COMMUNITY_ID',
            'COMMUNITY_NAME',
            ...($sheet instanceof EventSheet ? ['EVENT_START'] : []),
        ]));
    }

    /**
     * Run ImportValidator on one row of header → typed value.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed> the validator's result
     */
    public static function validate(ImportSheet $sheet, array $row): array
    {
        $headers = array_keys($sheet->rules());
        $values = array_map(fn ($h) => isset($row[$h]) && $row[$h] !== '' ? $row[$h] : null, $headers);

        return (new ImportValidator($sheet))->validateGrid([$headers, $values]);
    }

    /**
     * Whether the row's key fits the action: an add must be new, an edit or delete must find the row. Null = fine.
     *
     * @param  array<string, mixed>  $result  from validate()
     */
    public static function keyConflict(ImportSheet $sheet, string $source, array $result): ?string
    {
        $count = $result['summary']['replaceCount'] ?? 0;

        return match (true) {
            $source === 'web_add' && $count > 0 => 'มีข้อมูลของ '.self::label($sheet, $result['rows'][0]['values'] ?? []).' อยู่แล้ว กรุณาแก้ไขแถวเดิมแทน',
            in_array($source, ['web_edit', 'web_delete'], true) && $count === 0 => 'ไม่พบข้อมูลเดิมของแถวนี้ (อาจถูกลบหรือยกเลิกไปแล้ว)',
            default => null,
        };
    }

    /**
     * A short name for a row: "R001 · 2025-01-20" or "EV0001 · R001".
     *
     * @param  array<string, mixed>  $values
     */
    public static function label(ImportSheet $sheet, array $values): string
    {
        $parts = array_map(fn ($k) => (string) ($values[$k] ?? ''), $sheet->keyColumns());
        if ($sheet instanceof EventSheet && isset($values['COMMUNITY_ID'])) {
            $parts[] = (string) $values['COMMUNITY_ID'];
        }

        return implode(' · ', array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * Check one change and record it as a batch: applied at once, or pending when the user needs approval.
     * Field errors come back as a ValidationException keyed by header (the form shows them under each field).
     *
     * @param  'web_add'|'web_edit'|'web_delete'  $source
     * @param  array<string, mixed>  $row  header → typed value (for a delete: the row as it is now)
     * @param  array<string, mixed>|null  $before  the row as it was (edit/delete), for history and the audit trail
     * @return array{batch: ImportBatch, pending: bool, warnings: list<string>}
     */
    public static function submit(User $user, WorkGroup $group, string $source, array $row, ?array $before): array
    {
        $sheet = ImportSheets::for($group);
        if (! $sheet instanceof TableSheet) {
            throw ValidationException::withMessages(['_' => "ยังไม่รองรับการแก้ไขข้อมูล{$group->label()}"]);
        }

        $result = self::validate($sheet, $row);
        if ($result['errors']) {
            $messages = [];
            foreach ($result['errors'] as $error) {
                $messages[$error['column'] ?? '_'][] = $error['message'];
            }
            throw ValidationException::withMessages($messages);
        }
        if ($message = self::keyConflict($sheet, $source, $result)) {
            throw ValidationException::withMessages(['_' => $message]);
        }

        $values = $result['rows'][0]['values'];
        $pending = BatchApplier::needsApproval($user);
        $label = self::label($sheet, $values);

        $batch = DB::transaction(function () use ($user, $group, $source, $row, $before, $label, $pending) {
            $batch = ImportBatch::create([
                'work_group' => $group,
                'source' => $source,
                'status' => 'pending',
                'user_id' => $user->id,
                'row_count' => 1,
                'replaced_count' => 0,
                'community_count' => 1,
                'payload' => ['label' => $label, 'row' => $row, 'before' => $before],
            ]);
            if (! $pending && $error = BatchApplier::apply($batch)) {
                throw ValidationException::withMessages(['_' => $error]);
            }

            return $batch->refresh();
        });

        $changes = self::changes($before, $source === 'web_delete' ? null : $row);
        activity('imports')
            ->performedOn($batch)
            ->causedBy($user)
            ->event($source)
            ->withProperties([
                'old' => $changes['old'],
                'attributes' => $changes['new'],
                'details' => ['work_group' => $group->label(), 'row' => $label, 'status' => $pending ? 'รออนุมัติ' : 'บันทึกแล้ว'],
            ])
            ->log(ImportBatch::SOURCES[$source]." {$group->label()} {$label} #{$batch->id}");

        $warnings = array_values(array_unique(array_column($result['warnings'], 'message')));

        return ['batch' => $batch, 'pending' => $pending, 'warnings' => $warnings];
    }

    /**
     * The fields that differ between two rows (COMMUNITY_NAME left out: it is only a label).
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public static function changes(?array $before, ?array $after): array
    {
        $old = [];
        $new = [];
        foreach (array_unique([...array_keys($before ?? []), ...array_keys($after ?? [])]) as $field) {
            if ($field === 'COMMUNITY_NAME') {
                continue;
            }
            $b = isset($before[$field]) && $before[$field] !== '' ? (string) $before[$field] : null;
            $a = isset($after[$field]) && $after[$field] !== '' ? (string) $after[$field] : null;
            if ($b !== $a) {
                $old[$field] = $b;
                $new[$field] = $a;
            }
        }

        return ['old' => $old, 'new' => $new];
    }
}
