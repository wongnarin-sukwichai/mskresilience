<?php

namespace App\Import;

use App\Enums\UserRole;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Import history rows and the cancel rules, shared by ประวัตินำเข้า (ImportHistoryController, admin+staff) and
 * จัดการประวัตินำเข้า (ImportBatchManagementController, admin). Cancelling (owner decision 2026-11-03) deletes the
 * rows a batch added and gives the rows it replaced their old values back (ImportSheet::cancel()). It goes
 * newest first, and the batch row stays as history.
 */
class ImportHistory
{
    /** How long staff may cancel their own import. Admin has no limit. */
    public const CANCEL_HOURS = 24;

    /**
     * Who may cancel: admin any batch; staff only their own, within CANCEL_HOURS, while they still have the
     * group. Null = allowed.
     */
    public static function cancelNotAllowed(User $user, ImportBatch $batch): ?string
    {
        return match (true) {
            $batch->isCancelled() => 'รายการนี้ถูกยกเลิกไปแล้ว',
            ! $user->canImport($batch->work_group) => "คุณไม่มีสิทธิ์นำเข้าข้อมูล{$batch->work_group->label()}",
            $user->role === UserRole::Admin => null,
            $batch->user_id !== $user->id => 'ยกเลิกได้เฉพาะรายการที่คุณนำเข้าเอง',
            $batch->created_at->lt(now()->subHours(self::CANCEL_HOURS)) => 'เกิน '.self::CANCEL_HOURS.' ชั่วโมงหลังนำเข้าแล้ว กรุณาติดต่อผู้ดูแลระบบเพื่อยกเลิก',
            default => null,
        };
    }

    /**
     * Whether the batch's data can be undone right now, whoever asks. Null = it can.
     */
    public static function cancelBlocked(ImportBatch $batch): ?string
    {
        if (! ImportSheets::for($batch->work_group)) {
            return 'ยังไม่รองรับการยกเลิกข้อมูลกลุ่มงานนี้';
        }
        // Batches imported before old values were kept (2026-11-03) can't put back what they replaced.
        if ($batch->replaced_count > $batch->replacedRows()->count()) {
            return 'รายการนี้นำเข้าก่อนที่ระบบจะเก็บค่าเดิม จึงคืนค่าเดิมไม่ได้';
        }
        if ($later = $batch->replacedByBatchIds()) {
            $ids = implode(', ', array_map(fn ($id) => "#{$id}", $later));

            return "ข้อมูลบางแถวของรายการนี้ถูกรายการนำเข้า {$ids} ทับไปแล้ว กรุณายกเลิกรายการนั้นก่อน";
        }

        return null;
    }

    /**
     * @return array{id: int, group: array{value: string, label: string}, createdAt: string, user: string|null, filename: string, rowCount: int, replacedCount: int, communityCount: int, cancelledAt: string|null, cancelledBy: string|null, fileDeletedAt: string|null, fileDeletedBy: string|null, fileBytes: int|null, deletedFileBytes: int|null}
     */
    public static function summary(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'group' => ['value' => $batch->work_group->value, 'label' => $batch->work_group->label()],
            'createdAt' => $batch->created_at->toIso8601String(),
            'user' => $batch->user?->name,
            'filename' => $batch->original_filename,
            'rowCount' => $batch->row_count,
            'replacedCount' => $batch->replaced_count,
            'communityCount' => $batch->community_count,
            'cancelledAt' => $batch->cancelled_at?->toIso8601String(),
            'cancelledBy' => $batch->cancelledBy?->name,
            'fileDeletedAt' => $batch->file_deleted_at?->toIso8601String(),
            'fileDeletedBy' => $batch->fileDeletedBy?->name,
            // Size of the kept file on disk; null when it was deleted or is missing.
            'fileBytes' => ! $batch->isFileDeleted() && Storage::disk('local')->exists($batch->stored_path)
                ? Storage::disk('local')->size($batch->stored_path) : null,
            // Size the file had when an admin deleted it (shown struck through in the ขนาดไฟล์ column).
            'deletedFileBytes' => $batch->deleted_file_bytes,
        ];
    }

    /**
     * A history table row: the summary, plus whether this user may cancel it (`canCancel`: hide the button if
     * not) and, if so, why it can't be undone right now (`cancelBlocked`: button disabled with this reason).
     *
     * @return array<string, mixed>
     */
    public static function row(User $user, ImportBatch $batch): array
    {
        $canCancel = self::cancelNotAllowed($user, $batch) === null;

        return [
            ...self::summary($batch),
            'canCancel' => $canCancel,
            'cancelBlocked' => $canCancel ? self::cancelBlocked($batch) : null,
        ];
    }
}
