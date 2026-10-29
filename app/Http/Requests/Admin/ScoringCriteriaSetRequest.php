<?php

namespace App\Http\Requests\Admin;

use App\Models\ScoringCriteriaSet;
use App\Scoring\CriteriaSchema;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a set's name + full weights tree on update. The per-weight and per-row-sum checks come from
 * CriteriaSchema::validate() so the server enforces exactly what the UI shows.
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
        ];
    }
}
