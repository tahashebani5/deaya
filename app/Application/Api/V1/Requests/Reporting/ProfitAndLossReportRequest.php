<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The period a profit & loss summary is asked for.
 *
 * Both dates are required, unlike an activity log's optional `from`/`to`: a history defaults
 * sensibly to "everything so far", while a financial summary with no period would either scan the
 * whole business's history on every request or silently return zeroes — neither is a default
 * worth guessing at.
 */
class ProfitAndLossReportRequest extends FormRequest
{
    /**
     * Access is declared on the route with `can:reports.pnl.view`; there is nothing extra to
     * decide here.
     */
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
            'from' => ['required', 'date'],
            // Inclusive: a report for "today" carries everything recognised today, not stop at
            // midnight.
            'to' => ['required', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required' => 'بداية الفترة مطلوبة',
            'from.date' => 'تاريخ البداية غير صحيح',
            'to.required' => 'نهاية الفترة مطلوبة',
            'to.date' => 'تاريخ النهاية غير صحيح',
            'to.after_or_equal' => 'تاريخ النهاية يجب أن يكون بعد تاريخ البداية',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => 'بداية الفترة',
            'to' => 'نهاية الفترة',
        ];
    }
}
