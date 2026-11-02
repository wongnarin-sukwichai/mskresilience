<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkGroup;
use App\Http\Controllers\Controller;
use App\Import\ColumnDescriptions;
use App\Import\ImportColumns;
use App\Import\ImportExamples;
use App\Import\ImportSheets;
use App\Import\ImportValidator;
use App\Import\TemplateBuilder;
use App\Models\ImportBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * นำเข้าข้อมูล (จัดการข้อมูล module, admin+staff). Step 1 picks the กลุ่มงาน (one per input sheet of the
 * client's workbook); the user only ever sees and can open the groups User::canImport() allows — admin all 8,
 * staff the ones an admin granted. Step 2 offers the generated template (TemplateBuilder), then
 * upload → validate (ImportValidator, nothing saved) → staff confirms → live, with no admin review (owner
 * decision 2026-10-29). Only the groups ImportSheets supports can be uploaded so far.
 *
 * Between validate and confirm, the uploaded file waits in private storage (imports/tmp) and its path is kept
 * in the session. Confirm re-validates that same file, so what gets saved is exactly what was checked. On
 * confirm the file is moved to imports/Y/m/d and recorded in import_batches (history).
 */
class DataImportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('admin/DataImport', [
            'groups' => array_values(array_filter(WorkGroup::options(), fn ($g) => $user->canImport(WorkGroup::from($g['value'])))),
        ]);
    }

    public function show(Request $request, WorkGroup $group): Response|RedirectResponse
    {
        if (! $request->user()->canImport($group)) {
            return $this->refuse($group);
        }

        $preview = $request->session()->get($this->previewKey($group));
        $pending = $request->session()->get($this->pendingKey($group));

        return Inertia::render('admin/DataImportUpload', [
            'group' => ['value' => $group->value, 'label' => $group->label(), 'sheet' => $group->sheetName()],
            'columns' => $columns = ImportColumns::for($group),
            // For the "ตัวอย่างข้อมูล" viewer: the first line of each header comment, and the demo rows.
            'descriptions' => array_map(fn ($c) => strtok(ColumnDescriptions::ALL[$c] ?? '', "\n") ?: null, $columns),
            'exampleRows' => ImportExamples::for($group),
            'importReady' => ImportSheets::for($group) !== null,
            // The validation result of the file waiting for confirmation, if any (flashed by validateFile()).
            'preview' => $preview && $pending ? [...$preview, 'token' => $pending['token'], 'filename' => $pending['filename']] : null,
            'history' => ImportBatch::where('work_group', $group)->with('user:id,name')->latest('id')->limit(10)->get()
                ->map(fn (ImportBatch $b) => $this->batchSummary($b)),
            // The import just confirmed (flashed by confirm()), for the success card.
            'lastImport' => ($id = $request->session()->get('importedBatchId'))
                && ($batch = ImportBatch::with('user:id,name')->where('work_group', $group)->find($id))
                ? $this->batchSummary($batch) : null,
        ]);
    }

    public function template(Request $request, WorkGroup $group): StreamedResponse|RedirectResponse
    {
        if (! $request->user()->canImport($group)) {
            return $this->refuse($group);
        }

        $book = TemplateBuilder::build($group);

        return response()->streamDownload(
            fn () => (new Xlsx($book))->save('php://output'),
            "แบบฟอร์มนำเข้า_{$group->label()}.xlsx",
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Upload + validate. Nothing is saved to the data tables; the result is flashed for show() to display.
     */
    public function validateFile(Request $request, WorkGroup $group): RedirectResponse
    {
        if (! $request->user()->canImport($group)) {
            return $this->refuse($group);
        }
        if (! $sheet = ImportSheets::for($group)) {
            return back()->with('error', "ยังไม่รองรับการนำเข้าข้อมูล{$group->label()}");
        }

        $request->validate(
            ['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240']],
            [
                'file.required' => 'กรุณาเลือกไฟล์',
                'file.mimes' => 'รองรับเฉพาะไฟล์ Excel (.xlsx, .xls)',
                'file.max' => 'ไฟล์ต้องมีขนาดไม่เกิน 10 MB',
            ],
        );

        $this->discardPending($request, $group);
        $file = $request->file('file');
        $path = $file->store('imports/tmp', 'local');

        $result = (new ImportValidator($sheet))->validate(Storage::disk('local')->path($path));
        unset($result['saveRows']);

        $request->session()->put($this->pendingKey($group), [
            'token' => Str::uuid()->toString(),
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
        ]);
        $request->session()->put($this->previewKey($group), $result);

        return to_route('data-import.show', $group);
    }

    /**
     * Staff confirm → the checked file goes live immediately (re-validated first).
     */
    public function confirm(Request $request, WorkGroup $group): RedirectResponse
    {
        if (! $request->user()->canImport($group)) {
            return $this->refuse($group);
        }
        $sheet = ImportSheets::for($group);
        $pending = $request->session()->get($this->pendingKey($group));
        if (! $sheet || ! $pending || $pending['token'] !== $request->input('token') || ! Storage::disk('local')->exists($pending['path'])) {
            return to_route('data-import.show', $group)->with('error', 'ไม่พบไฟล์ที่รอนำเข้า กรุณาอัปโหลดไฟล์อีกครั้ง');
        }

        $result = (new ImportValidator($sheet))->validate(Storage::disk('local')->path($pending['path']));
        if ($result['errors'] || ! $result['saveRows']) {
            return to_route('data-import.show', $group)->with('error', 'ไฟล์ยังมีข้อผิดพลาด กรุณาแก้ไขแล้วอัปโหลดใหม่');
        }

        $finalPath = 'imports/'.now()->format('Y/m/d').'/'.Str::random(8).'_'.preg_replace('#[\\\\/:*?"<>|]#', '_', $pending['filename']);
        Storage::disk('local')->move($pending['path'], $finalPath);

        $batch = DB::transaction(function () use ($request, $group, $sheet, $result, $pending, $finalPath) {
            $batch = ImportBatch::create([
                'work_group' => $group,
                'user_id' => $request->user()->id,
                'original_filename' => $pending['filename'],
                'stored_path' => $finalPath,
                'row_count' => count($result['saveRows']),
                'replaced_count' => $result['summary']['replaceCount'],
                'community_count' => count($result['summary']['included']),
            ]);
            $sheet->save($result['saveRows'], $batch);

            return $batch;
        });

        $request->session()->forget([$this->pendingKey($group), $this->previewKey($group)]);

        // No `status` toast: the page shows its own "saving" steps and then a success card (lastImport),
        // and a toast would pop up before those finish.
        return to_route('data-import.show', $group)->with('importedBatchId', $batch->id);
    }

    /**
     * @return array{id: int, createdAt: string, user: string|null, filename: string, rowCount: int, replacedCount: int, communityCount: int}
     */
    private function batchSummary(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'createdAt' => $batch->created_at->toIso8601String(),
            'user' => $batch->user?->name,
            'filename' => $batch->original_filename,
            'rowCount' => $batch->row_count,
            'replacedCount' => $batch->replaced_count,
            'communityCount' => $batch->community_count,
        ];
    }

    /**
     * Cancel the file waiting for confirmation.
     */
    public function cancel(Request $request, WorkGroup $group): RedirectResponse
    {
        $this->discardPending($request, $group);

        return to_route('data-import.show', $group);
    }

    private function discardPending(Request $request, WorkGroup $group): void
    {
        if ($pending = $request->session()->pull($this->pendingKey($group))) {
            Storage::disk('local')->delete($pending['path']);
        }
        $request->session()->forget($this->previewKey($group));
    }

    private function pendingKey(WorkGroup $group): string
    {
        return "import.pending.{$group->value}";
    }

    private function previewKey(WorkGroup $group): string
    {
        return "import.preview.{$group->value}";
    }

    private function refuse(WorkGroup $group): RedirectResponse
    {
        return to_route('data-import.index')->with('error', "คุณไม่มีสิทธิ์นำเข้าข้อมูล{$group->label()}");
    }
}
