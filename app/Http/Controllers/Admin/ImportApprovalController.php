<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Import\BatchApplier;
use App\Import\ImportAudit;
use App\Import\ImportHistory;
use App\Models\ImportBatch;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * อนุมัติข้อมูล (จัดการข้อมูล module, admin; TOR 4.1.1(6)-1). When ตั้งค่าทั่วไป → "ต้องให้ผู้ดูแลอนุมัติก่อน" is on,
 * staff imports and web edits wait here (status pending, nothing saved). Approving checks the data again and saves
 * it (BatchApplier); rejecting needs a reason, which the importer sees in their history.
 */
class ImportApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('admin/ImportApprovals', [
            'batches' => ImportBatch::where('status', 'pending')->whereNull('cancelled_at')
                ->with('user:id,name', 'cancelledBy:id,name', 'fileDeletedBy:id,name', 'reviewedBy:id,name')->oldest('id')
                ->paginate(20)->withQueryString()
                ->through(fn (ImportBatch $b) => ImportHistory::row($user, $b)),
            'recent' => ImportBatch::whereNotNull('reviewed_at')
                ->with('user:id,name', 'cancelledBy:id,name', 'fileDeletedBy:id,name', 'reviewedBy:id,name')->latest('reviewed_at')
                ->limit(10)->get()
                ->map(fn (ImportBatch $b) => ImportHistory::row($user, $b)),
            'approvalOn' => (bool) Settings::get('import_requires_approval'),
            'cancelHours' => ImportHistory::CANCEL_HOURS,
        ]);
    }

    public function approve(Request $request, ImportBatch $batch): RedirectResponse
    {
        if (! $batch->isPending()) {
            return back()->with('error', 'รายการนี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว');
        }
        if ($error = BatchApplier::apply($batch)) {
            return back()->with('error', "อนุมัติ #{$batch->id} ไม่ได้: {$error} กรุณาไม่อนุมัติพร้อมแจ้งเหตุผล");
        }
        $batch->update(['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        ImportAudit::log($batch, 'approved', 'อนุมัติ'.ImportBatch::SOURCES[$batch->source]." {$batch->work_group->label()} #{$batch->id}", [
            'file' => $batch->original_filename ?? ($batch->payload['label'] ?? null),
            'rows' => $batch->row_count,
            'replaced' => $batch->replaced_count,
        ]);

        return back()->with('status', "อนุมัติ #{$batch->id} แล้ว ข้อมูลเข้าระบบแล้ว");
    }

    public function reject(Request $request, ImportBatch $batch): RedirectResponse
    {
        $note = $request->validate(['note' => ['required', 'string', 'max:500']], ['note.required' => 'กรุณาระบุเหตุผล'])['note'];
        if (! $batch->isPending()) {
            return back()->with('error', 'รายการนี้ไม่ได้อยู่ในสถานะรออนุมัติแล้ว');
        }
        $batch->update(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => trim($note)]);
        ImportAudit::log($batch, 'rejected', 'ไม่อนุมัติ'.ImportBatch::SOURCES[$batch->source]." {$batch->work_group->label()} #{$batch->id}", [
            'file' => $batch->original_filename ?? ($batch->payload['label'] ?? null),
            'note' => trim($note),
        ]);

        return back()->with('status', "ไม่อนุมัติ #{$batch->id} แล้ว");
    }
}
