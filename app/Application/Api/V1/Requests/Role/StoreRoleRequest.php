<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Role;

use App\Domain\Identity\Enums\PermissionName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'min:2', 'max:60',
                // Lowercase machine name: the gate and the code compare roles by this string.
                'regex:/^[a-z0-9_-]+$/',
                Rule::unique('roles', 'name')->where('guard_name', 'web')->withoutTrashed(),
            ],
            // Only permissions the code actually checks may be granted — anything else would be
            // a row that grants nothing and a checkbox that lies.
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(PermissionName::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'اسم الدور مطلوب',
            'name.regex' => 'اسم الدور يجب أن يكون أحرفاً إنجليزية صغيرة وأرقاماً وشرطات فقط',
            'name.unique' => 'اسم الدور مستخدم مسبقاً',
            'permissions.*.in' => 'الصلاحية المحددة غير معروفة في النظام',
            'permissions.*.distinct' => 'الصلاحية مكررة في القائمة',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'اسم الدور', 'permissions' => 'الصلاحيات'];
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return array_values(array_unique((array) ($this->validated('permissions') ?? [])));
    }
}
