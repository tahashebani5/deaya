<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What an employee is paid a month.
 *
 * **Nullable on purpose.** «لم يُحدَّد» is a state an account can genuinely be in — one created
 * before the wage was agreed — and it has to stay reachable, or a number typed by mistake is
 * permanent. `nullable` is what makes an explicit `null` a value rather than a missing field.
 *
 * Its own endpoint behind `users.salary`, never a field on the update form: see
 * EMPLOYEE-DETAIL-DESIGN.md §٢.
 */
class SetUserSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // A wage of nothing is a real thing to record (unpaid leave, a partner drawing no
            // salary); a wage below nothing is not. `numeric` rather than `decimal`, so «2500.5»
            // typed on a phone is accepted and stored as 2500.50 rather than refused for its
            // number of places.
            'salary' => ['present', 'nullable', 'numeric', 'min:0', 'max:99999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'salary.present' => 'الراتب مطلوب — أرسل قيمة أو فارغاً',
            'salary.numeric' => 'الراتب يجب أن يكون رقماً',
            'salary.min' => 'الراتب لا يمكن أن يكون سالباً',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['salary' => 'الراتب'];
    }

    /**
     * The value as the column stores it — a decimal string, never a float.
     *
     * Money never round-trips through a float in this codebase: `2500.10` is not exactly
     * representable, and the version that comes back out is the one somebody reads off a
     * payslip.
     */
    public function salary(): ?string
    {
        $salary = $this->validated('salary');

        return $salary === null ? null : (string) $salary;
    }
}
