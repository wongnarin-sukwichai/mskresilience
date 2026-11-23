<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkGroup;
use App\Http\Controllers\Controller;
use App\Import\BatchApplier;
use App\Import\ColumnDescriptions;
use App\Import\ImportSheets;
use App\Import\RecordEditor;
use App\Import\Sheets\ImportSheet;
use App\Import\Sheets\TableSheet;
use App\Models\Community;
use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * แก้ไขข้อมูล (จัดการข้อมูล module, admin+staff; TOR 4.1.1(6)-2: Data Owner "แก้ไขข้อมูลในขอบเขตที่ได้รับอนุญาต").
 * Lists the rows of one กลุ่มงาน the user may import, and adds, edits or deletes one row at a time. Every change
 * is checked with the import rules and recorded as its own batch (App\Import\RecordEditor), so it appears in the
 * import history, can be cancelled, and goes to the audit trail. With approval mode on, a staff change waits for an
 * admin.
 */
class DataRecordController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $groups = array_values(array_filter(WorkGroup::cases(), fn ($g) => $user->canImport($g) && ImportSheets::for($g) instanceof TableSheet));
        $filters = $request->validate([
            'group' => ['nullable', Rule::enum(WorkGroup::class)],
            'community' => ['nullable', 'string', 'max:10'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $group = isset($filters['group']) ? WorkGroup::from($filters['group']) : ($groups[0] ?? null);
        if ($group && ! in_array($group, $groups, true)) {
            $group = $groups[0] ?? null;
        }

        $sheet = $group ? ImportSheets::for($group) : null;
        $records = null;
        $columns = [];
        if ($sheet instanceof TableSheet) {
            $dateColumn = isset($sheet->rules()['ASSESSMENT_DATE']) ? 'assessment_date' : 'event_start';
            $community = ($filters['community'] ?? null) ? Community::where('code', strtoupper($filters['community']))->first() : null;
            $records = $sheet->recordModel()::query()
                ->with('community:id,code,name')
                ->when($community, fn ($q) => $q->where('community_id', $community->id))
                ->when(($filters['community'] ?? null) && ! $community, fn ($q) => $q->whereRaw('1 = 0'))
                ->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate($dateColumn, $date))
                ->orderByDesc($dateColumn)->orderBy('community_id')->orderBy('id')
                ->paginate(20)->withQueryString()
                ->through(fn ($record) => [
                    'id' => $record->id,
                    'values' => $sheet->recordValues($record),
                    'batchId' => $record->import_batch_id,
                    'updatedAt' => $record->updated_at?->toIso8601String(),
                ]);
            $locked = RecordEditor::lockedOnEdit($sheet);
            foreach ($sheet->rules() as $header => $rule) {
                $columns[] = [
                    'name' => $header,
                    'rule' => $rule,
                    'description' => strtok(ColumnDescriptions::ALL[$header] ?? '', "\n") ?: null,
                    'required' => in_array($rule, ImportSheet::REQUIRED, true),
                    'locked' => in_array($header, $locked, true),
                ];
            }
        }

        return Inertia::render('admin/DataRecords', [
            'groups' => array_map(fn (WorkGroup $g) => ['value' => $g->value, 'label' => $g->label()], $groups),
            'group' => $group?->value,
            'columns' => $columns,
            'records' => $records,
            'filters' => ['community' => $filters['community'] ?? '', 'date' => $filters['date'] ?? ''],
            'communities' => Community::orderBy('code')->get(['code', 'name']),
            'needsApproval' => BatchApplier::needsApproval($user),
        ]);
    }

    public function store(Request $request, WorkGroup $group): RedirectResponse
    {
        $this->authorizeGroup($request, $group);
        $row = $this->submittedValues($request);

        return $this->done(RecordEditor::submit($request->user(), $group, 'web_add', $row, null), 'เพิ่มข้อมูลแล้ว');
    }

    public function update(Request $request, WorkGroup $group, int $record): RedirectResponse
    {
        [$sheet, $model] = $this->findRecord($request, $group, $record);
        $before = $sheet->recordValues($model);
        // Locked fields (the key, community, an event's start) always keep their stored value.
        $row = [...$before, ...array_diff_key($this->submittedValues($request), array_flip(RecordEditor::lockedOnEdit($sheet)))];
        if (! RecordEditor::changes($before, $row)['new']) {
            return back()->with('error', 'ไม่มีการเปลี่ยนแปลงข้อมูล');
        }

        return $this->done(RecordEditor::submit($request->user(), $group, 'web_edit', $row, $before), 'บันทึกการแก้ไขแล้ว');
    }

    public function destroy(Request $request, WorkGroup $group, int $record): RedirectResponse
    {
        [$sheet, $model] = $this->findRecord($request, $group, $record);
        $before = $sheet->recordValues($model);

        return $this->done(RecordEditor::submit($request->user(), $group, 'web_delete', $before, $before), 'ลบข้อมูลแล้ว');
    }

    /**
     * @param  array{batch: ImportBatch, pending: bool, warnings: list<string>}  $result
     */
    private function done(array $result, string $message): RedirectResponse
    {
        $text = $result['pending']
            ? "ส่งรออนุมัติแล้ว (#{$result['batch']->id}) ข้อมูลจะเปลี่ยนเมื่อผู้ดูแลระบบอนุมัติ"
            : "{$message} (#{$result['batch']->id})";
        if ($result['warnings']) {
            $text .= ' · ข้อสังเกต: '.$result['warnings'][0];
        }

        return back()->with('status', $text);
    }

    /**
     * @return array<string, string|null>
     */
    private function submittedValues(Request $request): array
    {
        $values = $request->validate(['values' => ['required', 'array'], 'values.*' => ['nullable']])['values'];

        return array_map(fn ($v) => $v === null ? null : trim((string) $v), $values);
    }

    private function authorizeGroup(Request $request, WorkGroup $group): TableSheet
    {
        abort_unless($request->user()->canImport($group), 403);
        $sheet = ImportSheets::for($group);
        abort_unless($sheet instanceof TableSheet, 404);

        return $sheet;
    }

    /**
     * @return array{0: TableSheet, 1: Model}
     */
    private function findRecord(Request $request, WorkGroup $group, int $id): array
    {
        $sheet = $this->authorizeGroup($request, $group);
        $model = $sheet->recordModel()::with('community:id,code,name')->findOrFail($id);

        return [$sheet, $model];
    }
}
