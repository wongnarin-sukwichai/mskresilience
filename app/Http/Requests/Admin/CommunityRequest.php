<?php

namespace App\Http\Requests\Admin;

use App\Models\Community;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CommunityRequest extends FormRequest
{
    /**
     * Normalize before validating: codes are compared case-sensitively by the unique index, so "r033"
     * must become "R033", and a stray "ชุมชน" prefix is stripped since names are stored without it.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) preg_replace('/^ชุมชน\s*/u', '', trim((string) $this->input('name')))),
        ]);
    }

    /**
     * Get the validation rules that apply to the request. Shared by store and update. The code is set
     * once at creation and is immutable afterwards (product decision — it's the import join key, so a
     * mistyped code is fixed by deleting and re-adding), so on update it isn't validated or returned by
     * validated() at all, and any submitted code is ignored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique(Community::class)->ignore($this->route('community'))],
        ];

        if (! $this->route('community')) {
            $rules['code'] = ['required', 'regex:/^R\d{3}$/', Rule::unique(Community::class)];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'กรุณากรอกรหัสชุมชน',
            'code.regex' => 'รหัสชุมชนต้องอยู่ในรูปแบบ R ตามด้วยตัวเลข 3 หลัก เช่น R033',
            'code.unique' => 'รหัสชุมชนนี้มีอยู่แล้ว',
            'name.required' => 'กรุณากรอกชื่อชุมชน',
            'name.max' => 'ชื่อชุมชนยาวเกิน 255 ตัวอักษร',
            'name.unique' => 'ชื่อชุมชนนี้มีอยู่แล้ว',
        ];
    }
}
