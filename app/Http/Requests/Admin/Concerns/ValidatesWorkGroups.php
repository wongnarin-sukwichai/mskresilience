<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Enums\UserRole;
use App\Enums\WorkGroup;
use Illuminate\Validation\Rule;

/**
 * Shared by StoreUserRequest/UpdateUserRequest: staff must be granted at least one กลุ่มงาน; for admin
 * (implicitly all) and member (none) the field is ignored — see UserManagementController::workGroupsFor().
 */
trait ValidatesWorkGroups
{
    /**
     * @return array<string, mixed>
     */
    protected function workGroupRules(): array
    {
        $isStaff = $this->input('role') === UserRole::Staff->value;

        return [
            'work_groups' => $isStaff ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'work_groups.*' => [Rule::enum(WorkGroup::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'work_groups.required' => 'เจ้าหน้าที่ต้องได้รับสิทธิ์นำเข้าข้อมูลอย่างน้อย 1 กลุ่มงาน',
            'work_groups.min' => 'เจ้าหน้าที่ต้องได้รับสิทธิ์นำเข้าข้อมูลอย่างน้อย 1 กลุ่มงาน',
        ];
    }
}
