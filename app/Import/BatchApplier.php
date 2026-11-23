<?php

namespace App\Import;

use App\Enums\UserRole;
use App\Import\Sheets\TableSheet;
use App\Models\ImportBatch;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Puts a batch's data into the tables, for an uploaded file or a web-form change (RecordEditor), either right away
 * or when an admin approves it (approval mode, TOR 4.1.1(6)-1, owner 2026-11-23).
 *
 * The data is always checked again at the moment it is applied, with the same ImportValidator rules as an upload:
 * a pending batch may wait for days, and other imports may have changed the data meanwhile.
 */
class BatchApplier
{
    /**
     * Whether this user's imports and edits wait for an admin. Admins never wait (they are the approvers).
     */
    public static function needsApproval(User $user): bool
    {
        return (bool) Settings::get('import_requires_approval') && $user->role !== UserRole::Admin;
    }

    /**
     * Validate and save the batch's data. Returns an error message (nothing saved) or null. The batch becomes
     * active with its counts updated to what was actually saved. Runs in a transaction.
     */
    public static function apply(ImportBatch $batch): ?string
    {
        $sheet = ImportSheets::for($batch->work_group);
        if (! $sheet instanceof TableSheet) {
            return "ยังไม่รองรับการนำเข้าข้อมูล{$batch->work_group->label()}";
        }

        if ($batch->source === 'file') {
            $disk = Storage::disk('local');
            if (! $batch->stored_path || ! $disk->exists($batch->stored_path)) {
                return 'ไม่พบไฟล์ของรายการนี้ จึงนำข้อมูลเข้าไม่ได้';
            }
            $result = (new ImportValidator($sheet))->validate($disk->path($batch->stored_path));
        } else {
            $result = RecordEditor::validate($sheet, $batch->payload['row'] ?? []);
        }
        if ($result['errors'] || ! $result['saveRows']) {
            return 'ข้อมูลไม่ผ่านการตรวจสอบ: '.($result['errors'][0]['message'] ?? 'ไม่พบข้อมูล');
        }
        if ($message = RecordEditor::keyConflict($sheet, $batch->source, $result)) {
            return $message;
        }

        DB::transaction(function () use ($batch, $sheet, $result) {
            if ($batch->source === 'web_delete') {
                $sheet->deleteRows($result['saveRows'], $batch);
            } else {
                $sheet->save($result['saveRows'], $batch);
            }
            $batch->update([
                'status' => 'active',
                'row_count' => count($result['saveRows']),
                'replaced_count' => $batch->source === 'web_delete' ? 0 : $result['summary']['replaceCount'],
                'community_count' => count($result['summary']['included']),
            ]);
        });

        return null;
    }
}
