<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncUserRolesRequest extends FormRequest
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
            // The complete set the user should end up with. An empty array strips every role.
            'roles' => ['present', 'array'],
            'roles.*' => ['required', 'string', 'distinct', Rule::exists('roles', 'name')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.present' => 'قائمة الأدوار مطلوبة',
            'roles.array' => 'الأدوار يجب أن تكون قائمة',
            'roles.*.exists' => 'الدور المحدد غير موجود',
            'roles.*.distinct' => 'الدور مكرر في القائمة',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['roles' => 'الأدوار'];
    }

    /**
     * @return array<int, string>
     */
    public function roleNames(): array
    {
        return array_values(array_unique((array) $this->validated('roles')));
    }
}
