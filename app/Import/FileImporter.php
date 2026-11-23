<?php

namespace App\Import;

use App\Enums\WorkGroup;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Confirming an uploaded file: check it again, keep the original under imports/Y/m/d/, record the batch and save
 * the rows (or leave it pending when the user needs approval). Shared by DataImportController::confirm() and the
 * demo-data command, so mock data goes in exactly the way a staff upload does.
 */
class FileImporter
{
    /**
     * @param  string  $tmpPath  path on the local disk of the file waiting for confirmation (moved on success)
     * @return array{batch: ImportBatch|null, error: string|null}
     */
    public static function confirm(WorkGroup $group, User $user, string $tmpPath, string $originalName): array
    {
        $sheet = ImportSheets::for($group);
        $disk = Storage::disk('local');
        if (! $sheet || ! $disk->exists($tmpPath)) {
            return ['batch' => null, 'error' => 'ไม่พบไฟล์ที่รอนำเข้า กรุณาอัปโหลดไฟล์อีกครั้ง'];
        }

        $result = (new ImportValidator($sheet))->validate($disk->path($tmpPath));
        if ($result['errors'] || ! $result['saveRows']) {
            return ['batch' => null, 'error' => 'ไฟล์ยังมีข้อผิดพลาด กรุณาแก้ไขแล้วอัปโหลดใหม่'];
        }

        $finalPath = 'imports/'.now()->format('Y/m/d').'/'.Str::random(8).'_'.preg_replace('#[\\\\/:*?"<>|]#', '_', $originalName);
        $disk->move($tmpPath, $finalPath);

        // Approval mode (TOR 4.1.1(6)-1): a staff import waits for an admin and nothing is saved yet. The admin's
        // approval checks the kept file again and saves it (BatchApplier).
        $needsApproval = BatchApplier::needsApproval($user);
        $batch = DB::transaction(function () use ($user, $group, $sheet, $result, $originalName, $finalPath, $needsApproval) {
            $batch = ImportBatch::create([
                'work_group' => $group,
                'status' => $needsApproval ? 'pending' : 'active',
                'user_id' => $user->id,
                'original_filename' => $originalName,
                'stored_path' => $finalPath,
                'row_count' => count($result['saveRows']),
                'replaced_count' => $result['summary']['replaceCount'],
                'community_count' => count($result['summary']['included']),
            ]);
            if (! $needsApproval) {
                $sheet->save($result['saveRows'], $batch);
            }

            return $batch;
        });
        activity('imports')->causedBy($user)->performedOn($batch)->event($needsApproval ? 'submitted' : 'imported')
            ->withProperties(['details' => [
                'work_group' => $group->label(),
                'file' => $batch->original_filename,
                'rows' => $batch->row_count,
                'replaced' => $batch->replaced_count,
                'communities' => $batch->community_count,
            ]])
            ->log(($needsApproval ? 'ส่งข้อมูลรออนุมัติ' : 'นำเข้าข้อมูล')." {$group->label()} #{$batch->id}");

        return ['batch' => $batch, 'error' => null];
    }
}
