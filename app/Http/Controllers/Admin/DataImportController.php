<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkGroup;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * นำเข้าข้อมูล (จัดการข้อมูล module, admin+staff). Step 1 picks the กลุ่มงาน (one per input sheet of the
 * client's workbook); the user only ever sees and can open the groups User::canImport() allows — admin all 8,
 * staff the ones an admin granted. The upload page itself is a shell for now: parsing, validation, staging and
 * the review workflow (see memory: import workflow v2) come later, sheet by sheet.
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
            return to_route('data-import.index')->with('error', "คุณไม่มีสิทธิ์นำเข้าข้อมูล{$group->label()}");
        }

        return Inertia::render('admin/DataImportUpload', [
            'group' => ['value' => $group->value, 'label' => $group->label(), 'sheet' => $group->sheetName()],
        ]);
    }
}
