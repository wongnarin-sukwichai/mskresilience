<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkGroup;
use App\Http\Controllers\Controller;
use App\Import\ImportHistory;
use App\Models\ImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * จัดการประวัตินำเข้า (ตั้งค่าระบบ module, admin): every import of every กลุ่มงาน, searchable by importer name,
 * กลุ่มงาน and import date range. Filtering is server-side, so it covers all pages. Cancel posts to
 * ImportHistoryController::cancel(). A summary card shows how many original files are kept and their size.
 */
class ImportBatchManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', Rule::enum(WorkGroup::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(['active', 'cancelled', 'file_kept', 'file_deleted'])],
        ]);
        $user = $request->user();

        $batches = ImportBatch::query()
            ->when($filters['user'] ?? null, fn ($q, $name) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$name.'%')))
            ->when($filters['group'] ?? null, fn ($q, $group) => $q->where('work_group', $group))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'active' => $q->whereNull('cancelled_at'),
                'cancelled' => $q->whereNotNull('cancelled_at'),
                'file_kept' => $q->whereNull('file_deleted_at'),
                'file_deleted' => $q->whereNotNull('file_deleted_at'),
            })
            ->with('user:id,name', 'cancelledBy:id,name', 'fileDeletedBy:id,name')->latest('id')
            ->paginate(20)->withQueryString()
            ->through(fn (ImportBatch $b) => ImportHistory::row($user, $b));

        return Inertia::render('admin/ImportBatches', [
            'batches' => $batches,
            'filters' => [
                'user' => $filters['user'] ?? '',
                'group' => $filters['group'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'groups' => WorkGroup::options(),
            'cancelHours' => ImportHistory::CANCEL_HOURS,
            'storage' => $this->storageSummary(),
        ]);
    }

    /**
     * Delete the kept original files of the selected batches to free disk space (owner decision 2026-11-03: any
     * batch, active or cancelled). Only the file goes; the imported data and the history row stay, marked with
     * who/when and the size freed. Batches whose file was already deleted are skipped.
     */
    public function deleteFiles(Request $request): RedirectResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'exists:import_batches,id'],
        ])['ids'];
        $disk = Storage::disk('local');
        $count = 0;
        $bytes = 0;

        foreach (ImportBatch::whereIn('id', $ids)->whereNull('file_deleted_at')->get() as $batch) {
            $size = $disk->exists($batch->stored_path) ? $disk->size($batch->stored_path) : 0;
            $disk->delete($batch->stored_path);
            $batch->update(['file_deleted_at' => now(), 'file_deleted_by' => $request->user()->id, 'deleted_file_bytes' => $size]);
            $count++;
            $bytes += $size;
        }

        return back()->with('status', $count
            ? "ลบไฟล์ต้นฉบับ {$count} ไฟล์แล้ว คืนพื้นที่ ".$this->formatBytes($bytes)
            : 'ไฟล์ที่เลือกถูกลบไปก่อนแล้ว');
    }

    /**
     * Same format as ImportBatches.vue's formatBytes(). Not Number::fileSize(), which needs the intl extension
     * (missing on the XAMPP dev setup).
     */
    private function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => "{$bytes} B",
            $bytes < 1024 ** 2 => number_format($bytes / 1024, 1).' KB',
            $bytes < 1024 ** 3 => number_format($bytes / 1024 ** 2, 1).' MB',
            default => number_format($bytes / 1024 ** 3, 2).' GB',
        };
    }

    /**
     * The kept original files, over all batches regardless of the filters: how many, how much disk, how many
     * an admin deleted (and the space freed), and which history rows have lost their file without anyone
     * deleting it. Sizes are read from disk, not stored, so a file removed or not restored on the server
     * shows up as missing.
     *
     * @return array{files: int, bytes: int, activeFiles: int, cancelledFiles: int, deletedFiles: int, deletedBytes: int, missing: list<int>}
     */
    private function storageSummary(): array
    {
        $disk = Storage::disk('local');
        $summary = ['files' => 0, 'bytes' => 0, 'activeFiles' => 0, 'cancelledFiles' => 0, 'deletedFiles' => 0, 'deletedBytes' => 0, 'missing' => []];

        foreach (ImportBatch::select('id', 'stored_path', 'cancelled_at', 'file_deleted_at', 'deleted_file_bytes')->orderBy('id')->lazy() as $batch) {
            if ($batch->isFileDeleted()) {
                $summary['deletedFiles']++;
                $summary['deletedBytes'] += (int) $batch->deleted_file_bytes;

                continue;
            }
            if (! $disk->exists($batch->stored_path)) {
                $summary['missing'][] = $batch->id;

                continue;
            }
            $summary['files']++;
            $summary['bytes'] += $disk->size($batch->stored_path);
            $summary[$batch->isCancelled() ? 'cancelledFiles' : 'activeFiles']++;
        }

        return $summary;
    }
}
