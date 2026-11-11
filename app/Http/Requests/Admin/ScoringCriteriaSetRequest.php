<?php

namespace App\Http\Requests\Admin;

use App\Models\ScoringCriteriaSet;
use App\Scoring\CriteriaSchema;
use App\Scoring\CriteriaSets;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a set's name, start fiscal year and full weights tree on update. A start year that moves existing
 * data to another set needs the admin's confirmation (confirm_move, CriteriaSets::moves, owner 2026-11-11). The
 * per-weight and per-row-sum checks come from CriteriaSchema::validate() so the server enforces exactly what the
 * UI shows.
 */
class ScoringCriteriaSetRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique(ScoringCriteriaSet::class)->ignore($this->route('set'))],
            'start_fiscal_year' => ['required', 'integer', 'between:2500,2700', Rule::unique(ScoringCriteriaSet::class)->ignore($this->route('set'))],
            'weights' => ['required', 'array'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('weights')) {
                    return;
                }
                foreach (CriteriaSchema::validate($this->input('weights', [])) as $key => $message) {
                    $validator->errors()->add($key, $message);
                }
            },
            // Last, and only on an otherwise valid form, so the confirmation never hides a real error. A start year
            // that moves years with data to another set needs confirm_move (owner 2026-11-11).
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->boolean('confirm_move')) {
                    return;
                }
                $moves = CriteriaSets::moves($this->route('set'), (int) $this->input('start_fiscal_year'), $this->input('name'));
                if ($moves) {
                    $validator->errors()->add('confirm_move', CriteriaSets::movesMessage($moves));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'กรุณาตั้งชื่อชุดเกณฑ์',
            'name.max' => 'ชื่อชุดเกณฑ์ยาวเกิน 255 ตัวอักษร',
            'name.unique' => 'ชื่อชุดเกณฑ์นี้มีอยู่แล้ว',
            'weights.required' => 'ไม่พบค่าน้ำหนัก',
            'start_fiscal_year.required' => 'กรุณาระบุปีงบประมาณที่เริ่มใช้',
            'start_fiscal_year.integer' => 'ปีงบประมาณต้องเป็นตัวเลข พ.ศ.',
            'start_fiscal_year.between' => 'ปีงบประมาณต้องเป็น พ.ศ. ระหว่าง 2500–2700',
            'start_fiscal_year.unique' => 'มีชุดเกณฑ์ที่เริ่มใช้ปีงบประมาณนี้แล้ว',
        ];
    }
}
