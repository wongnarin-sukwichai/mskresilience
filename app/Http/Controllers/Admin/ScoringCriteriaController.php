<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ScoringCriteriaSetRequest;
use App\Models\ScoringCriteriaSet;
use App\Scoring\CriteriaSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * เกณฑ์กลาง (central scoring criteria) sets. Rules confirmed with the project owner 2026-10-29:
 * exactly one set is active; admins lock/unlock manually and a locked set can't be edited; any set can be
 * cloned (the clone starts unlocked and inactive); only a set that is neither active nor locked can be deleted.
 */
class ScoringCriteriaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/ScoringCriteria', [
            'sets' => ScoringCriteriaSet::query()->orderByDesc('is_active')->orderBy('name')->get(),
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
            'copy_from' => ['required', Rule::exists(ScoringCriteriaSet::class, 'id')],
        ], [
            'name.required' => 'กรุณาตั้งชื่อชุดเกณฑ์',
            'name.unique' => 'ชื่อชุดเกณฑ์นี้มีอยู่แล้ว',
        ]);

        $source = ScoringCriteriaSet::findOrFail($validated['copy_from']);

        ScoringCriteriaSet::create([
            'name' => $validated['name'],
            'is_active' => false,
            'is_locked' => false,
            'weights' => $source->weights,
        ]);

        return back()->with('status', 'คัดลอกชุดเกณฑ์เรียบร้อยแล้ว');
    }

    public function update(ScoringCriteriaSetRequest $request, ScoringCriteriaSet $set): RedirectResponse
    {
        abort_if($set->is_locked, 403, 'ชุดเกณฑ์นี้ถูกล็อกอยู่ ต้องปลดล็อกก่อนจึงจะแก้ไขได้');

        $set->update([
            'name' => $request->validated('name'),
            'weights' => CriteriaSchema::normalize($request->validated('weights')),
        ]);

        return back()->with('status', 'บันทึกชุดเกณฑ์เรียบร้อยแล้ว');
    }

    public function destroy(ScoringCriteriaSet $set): RedirectResponse
    {
        abort_if($set->is_active, 403, 'ไม่สามารถลบชุดเกณฑ์ที่ใช้งานอยู่ได้');
        abort_if($set->is_locked, 403, 'ไม่สามารถลบชุดเกณฑ์ที่ถูกล็อกอยู่ได้');

        $set->delete();

        return back()->with('status', 'ลบชุดเกณฑ์เรียบร้อยแล้ว');
    }

    /**
     * Make this the one active set; every other set is deactivated.
     */
    public function activate(ScoringCriteriaSet $set): RedirectResponse
    {
        DB::transaction(function () use ($set) {
            ScoringCriteriaSet::where('id', '!=', $set->id)->update(['is_active' => false]);
            $set->update(['is_active' => true]);
        });

        return back()->with('status', "ตั้ง \"{$set->name}\" เป็นชุดที่ใช้งานแล้ว");
    }

    public function lock(ScoringCriteriaSet $set): RedirectResponse
    {
        $set->update(['is_locked' => true]);

        return back()->with('status', 'ล็อกชุดเกณฑ์แล้ว');
    }

    public function unlock(ScoringCriteriaSet $set): RedirectResponse
    {
        $set->update(['is_locked' => false]);

        return back()->with('status', 'ปลดล็อกชุดเกณฑ์แล้ว แก้ไขได้');
    }
}
