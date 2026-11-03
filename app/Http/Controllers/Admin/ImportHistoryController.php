<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkGroup;
use App\Http\Controllers\Controller;
use App\Import\ImportFileReader;
use App\Import\ImportHistory;
use App\Import\ImportSheets;
use App\Models\ImportBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ประวัตินำเข้า (จัดการข้อมูล module, admin+staff): the confirmed imports of every กลุ่มงาน this user may import
 * (admin all 8, staff the granted ones), newest first. Cancelling lives here too, and จัดการประวัตินำเข้า
 * (admin) posts to the same cancel route. The rules are in ImportHistory.
 */
class ImportHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $groups = array_values(array_filter(WorkGroup::cases(), fn ($g) => $user->canImport($g)));

        return Inertia::render('admin/ImportHistory', [
            'batches' => ImportBatch::whereIn('work_group', $groups)
                ->with('user:id,name', 'cancelledBy:id,name', 'fileDeletedBy:id,name')->latest('id')
                ->paginate(20)->withQueryString()
                ->through(fn (ImportBatch $b) => ImportHistory::row($user, $b)),
            'cancelHours' => ImportHistory::CANCEL_HOURS,
        ]);
    }

    /**
     * "ดูข้อมูล": the batch's kept file as-is, with each row's current status (ImportFileReader). JSON, fetched
     * by ImportHistoryTable's modal. Same visibility as the history list: users who may import the group.
     */
    public function data(Request $request, ImportBatch $batch): JsonResponse
    {
        abort_unless($request->user()->canImport($batch->work_group), 403);
        $batch->load('user:id,name', 'cancelledBy:id,name', 'fileDeletedBy:id,name');

        return response()->json([
            'batch' => ImportHistory::summary($batch),
            'maxRows' => ImportFileReader::MAX_ROWS,
            ...ImportFileReader::read($batch),
        ]);
    }

    public function cancel(Request $request, ImportBatch $batch): RedirectResponse
    {
        if ($message = ImportHistory::cancelNotAllowed($request->user(), $batch) ?? ImportHistory::cancelBlocked($batch)) {
            return back()->with('error', $message);
        }

        DB::transaction(function () use ($request, $batch) {
            ImportSheets::for($batch->work_group)->cancel($batch);
            $batch->update(['cancelled_at' => now(), 'cancelled_by' => $request->user()->id]);
        });

        return back()->with('status', "ยกเลิกรายการนำเข้า #{$batch->id} แล้ว");
    }
}
