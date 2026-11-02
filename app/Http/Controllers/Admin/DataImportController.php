<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkGroup;
use App\Http\Controllers\Controller;
use App\Import\ColumnDescriptions;
use App\Import\ImportColumns;
use App\Import\ImportExamples;
use App\Import\TemplateBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * นำเข้าข้อมูล (จัดการข้อมูล module, admin+staff). Step 1 picks the กลุ่มงาน (one per input sheet of the
 * client's workbook); the user only ever sees and can open the groups User::canImport() allows — admin all 8,
 * staff the ones an admin granted. Step 2 offers the generated template (TemplateBuilder); upload, validation
 * and saving (see memory: import workflow v2) come next, sheet by sheet.
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

        return Inertia::render('admin/DataImportUpload', [
            'group' => ['value' => $group->value, 'label' => $group->label(), 'sheet' => $group->sheetName()],
            'columns' => $columns = ImportColumns::for($group),
            // For the "ตัวอย่างข้อมูล" viewer: the first line of each header comment, and the demo rows.
            'descriptions' => array_map(fn ($c) => strtok(ColumnDescriptions::ALL[$c] ?? '', "\n") ?: null, $columns),
            'exampleRows' => ImportExamples::for($group),
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

    private function refuse(WorkGroup $group): RedirectResponse
    {
        return to_route('data-import.index')->with('error', "คุณไม่มีสิทธิ์นำเข้าข้อมูล{$group->label()}");
    }
}
