<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ScoringCriteriaSetRequest;
use App\Models\ScoringCriteriaSet;
use App\Scoring\CriteriaSchema;
use App\Scoring\CriteriaSets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * เกณฑ์กลาง (central scoring criteria) sets.
 * - Each set applies from its start ปีงบประมาณ (owner decision 2026-11-06; see App\Scoring\CriteriaSets).
 * - A set used by imported data is read-only and can't be unlocked or deleted.
 * - Admins can also lock/unlock an unused set manually, and a locked set can't be edited.
 * - Any set can be cloned into a new unlocked set with its own start year.
 * - Only an unused, unlocked set can be deleted, and never the last one.
 */
class ScoringCriteriaController extends Controller
{
    public function index(): Response
    {
        $sets = ScoringCriteriaSet::query()->orderBy('start_fiscal_year')->get();
        $dataYears = CriteriaSets::dataFiscalYears();
        $usage = CriteriaSets::usage($sets, $dataYears);

        return Inertia::render('admin/ScoringCriteria', [
            'sets' => $sets->values()->map(fn (ScoringCriteriaSet $set, int $i) => [
                ...$set->toArray(),
                // The last fiscal year this set covers (the year before the next set starts), null = open-ended.
                'end_fiscal_year' => isset($sets[$i + 1]) ? $sets[$i + 1]->start_fiscal_year - 1 : null,
                'used_years' => $usage[$set->id] ?? [],
            ]),
            'dataYears' => $dataYears,
            'currentFiscalYear' => CriteriaSets::fiscalYear(now()),
            'schema' => CriteriaSchema::tabs(),
        ]);
    }

    /**
     * Create a new set by cloning an existing one.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique(ScoringCriteriaSet::class)],
            'start_fiscal_year' => ['required', 'integer', 'between:2500,2700', Rule::unique(ScoringCriteriaSet::class)],
            'copy_from' => ['required', Rule::exists(ScoringCriteriaSet::class, 'id')],
        ], [
            'name.required' => 'กรุณาตั้งชื่อชุดเกณฑ์',
            'name.unique' => 'ชื่อชุดเกณฑ์นี้มีอยู่แล้ว',
            'start_fiscal_year.required' => 'กรุณาระบุปีงบประมาณที่เริ่มใช้',
            'start_fiscal_year.integer' => 'ปีงบประมาณต้องเป็นตัวเลข พ.ศ.',
            'start_fiscal_year.between' => 'ปีงบประมาณต้องเป็น พ.ศ. ระหว่าง 2500–2700',
            'start_fiscal_year.unique' => 'มีชุดเกณฑ์ที่เริ่มใช้ปีงบประมาณนี้แล้ว',
        ]);

        $conflict = CriteriaSets::conflict(new ScoringCriteriaSet, (int) $validated['start_fiscal_year']);
        if ($conflict) {
            throw ValidationException::withMessages(['start_fiscal_year' => $conflict]);
        }

        $source = ScoringCriteriaSet::findOrFail($validated['copy_from']);

        ScoringCriteriaSet::create([
            'name' => $validated['name'],
            'start_fiscal_year' => (int) $validated['start_fiscal_year'],
            'is_locked' => false,
            'weights' => $source->weights,
        ]);

        return back()->with('status', 'คัดลอกชุดเกณฑ์เรียบร้อยแล้ว');
    }

    public function update(ScoringCriteriaSetRequest $request, ScoringCriteriaSet $set): RedirectResponse
    {
        if ($refusal = $this->editRefusal($set)) {
            return back()->with('error', $refusal);
        }

        $set->update([
            'name' => $request->validated('name'),
            'start_fiscal_year' => (int) $request->validated('start_fiscal_year'),
            'weights' => CriteriaSchema::normalize($request->validated('weights')),
        ]);

        return back()->with('status', 'บันทึกชุดเกณฑ์เรียบร้อยแล้ว');
    }

    public function destroy(ScoringCriteriaSet $set): RedirectResponse
    {
        if ($refusal = $this->editRefusal($set)) {
            return back()->with('error', $refusal);
        }
        if (ScoringCriteriaSet::count() <= 1) {
            return back()->with('error', 'ต้องมีชุดเกณฑ์อย่างน้อย 1 ชุด');
        }

        $set->delete();

        return back()->with('status', 'ลบชุดเกณฑ์เรียบร้อยแล้ว');
    }

    public function lock(ScoringCriteriaSet $set): RedirectResponse
    {
        $set->update(['is_locked' => true]);

        return back()->with('status', 'ล็อกชุดเกณฑ์แล้ว');
    }

    public function unlock(ScoringCriteriaSet $set): RedirectResponse
    {
        if (CriteriaSets::inUse($set)) {
            return back()->with('error', 'ชุดเกณฑ์นี้มีข้อมูลใช้คำนวณแล้ว จึงปลดล็อกไม่ได้');
        }

        $set->update(['is_locked' => false]);

        return back()->with('status', 'ปลดล็อกชุดเกณฑ์แล้ว แก้ไขได้');
    }

    /**
     * Why the set can't be edited or deleted, or null if it can.
     */
    private function editRefusal(ScoringCriteriaSet $set): ?string
    {
        if (CriteriaSets::inUse($set)) {
            return 'ชุดเกณฑ์นี้มีข้อมูลใช้คำนวณแล้ว จึงแก้ไขหรือลบไม่ได้ (คัดลอกเป็นชุดใหม่แทนได้)';
        }
        if ($set->is_locked) {
            return 'ชุดเกณฑ์นี้ถูกล็อกอยู่ ต้องปลดล็อกก่อน';
        }

        return null;
    }
}
