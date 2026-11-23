<?php

namespace App\Http\Controllers\Admin;

use App\Backup\BackupService;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * สำรองข้อมูล (ตั้งค่าระบบ module, admin; TOR 4.4-5, คุณลักษณะ 2.1-5 "แสดง Log Backup"). Two buttons (database,
 * import files), the backup log, and downloads, so a copy can be kept off the server. Backups also run on a schedule
 * when the host has cron (routes/console.php); the log shows which way each one ran.
 */
class BackupController extends Controller
{
    public function index(): Response
    {
        $last = fn (string $type) => Backup::where('type', $type)->where('status', 'success')->latest('id')->first();

        return Inertia::render('admin/Backups', [
            'backups' => Backup::with('user:id,name')->latest('id')->paginate(20)->through(fn (Backup $b) => [
                'id' => $b->id,
                'type' => $b->type,
                'typeLabel' => Backup::TYPES[$b->type] ?? $b->type,
                'trigger' => $b->trigger,
                'status' => $b->status,
                'sizeBytes' => $b->size_bytes,
                'message' => $b->message,
                'user' => $b->user?->name,
                'startedAt' => $b->started_at?->toIso8601String(),
                'finishedAt' => $b->finished_at?->toIso8601String(),
                'seconds' => $b->started_at && $b->finished_at ? $b->started_at->diffInSeconds($b->finished_at) : null,
                'downloadable' => $b->status === 'success' && ! $b->file_deleted_at && $b->path && Storage::disk('local')->exists($b->path),
                'fileDeleted' => $b->file_deleted_at !== null,
            ]),
            'last' => [
                'database' => $last('database')?->finished_at?->toIso8601String(),
                'files' => $last('files')?->finished_at?->toIso8601String(),
            ],
            'daysSinceDatabase' => BackupService::daysSinceLastDatabaseBackup(),
            'overdueDays' => (int) config('backup.overdue_days'),
            'keep' => config('backup.keep'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(array_keys(Backup::TYPES))]])['type'];
        $backup = BackupService::run($type, 'manual', $request->user());

        return $backup->status === 'success'
            ? back()->with('status', 'สำรอง'.Backup::TYPES[$type]." เรียบร้อยแล้ว ({$backup->message})")
            : back()->with('error', 'สำรองข้อมูลไม่สำเร็จ: '.$backup->message);
    }

    public function download(Request $request, Backup $backup): StreamedResponse|RedirectResponse
    {
        $disk = Storage::disk('local');
        if ($backup->status !== 'success' || $backup->file_deleted_at || ! $backup->path || ! $disk->exists($backup->path)) {
            return back()->with('error', 'ไม่พบไฟล์สำรองนี้แล้ว');
        }
        // The file holds the whole database (account emails, hashed passwords), so every download is on record.
        activity('backups')->performedOn($backup)->causedBy($request->user())->event('backup_downloaded')
            ->withProperties(['details' => ['type' => Backup::TYPES[$backup->type] ?? $backup->type, 'file' => basename($backup->path)]])
            ->log('ดาวน์โหลดไฟล์สำรอง'.(Backup::TYPES[$backup->type] ?? '')." #{$backup->id}");

        return $disk->download($backup->path, basename($backup->path));
    }
}
