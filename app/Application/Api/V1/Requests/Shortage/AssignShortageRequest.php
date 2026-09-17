<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Shortage;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Handing a shortage to somebody, or taking it out of everybody's queue.
 *
 * **`present` rather than `required`**, which is the whole of the rule: unassigning sends
 * `assigned_to_user_id: null` and must be told apart from a payload that forgot the field. The
 * first is a decision and the second is a bug, and `required` would refuse them both alike.
 */
class AssignShortageRequest extends FormRequest
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
            'assigned_to_user_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assigned_to_user_id.present' => 'حقل الموظف المسؤول مطلوب — أرسل قيمة فارغة لإلغاء الإسناد',
            'assigned_to_user_id.exists' => 'الموظف غير موجود',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['assigned_to_user_id' => 'الموظف المسؤول'];
    }
}
