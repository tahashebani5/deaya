<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Reporting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The period a sales statistics board is asked for.
 *
 * The same two required dates as {@see ProfitAndLossReportRequest}, and required for the same
 * reason: a board with no period would either scan the whole business's history on every request
 * or silently return zeroes, and neither is a default worth guessing at.
 *
 * **«اليوم» and «هذا الشهر» are not values here.** The caller resolves them before asking, in the
 * shop's own timezone — see {@see SalesStatisticsFilters}.
 */
class SalesStatisticsReportRequest extends FormRequest
{
    /**
     * Access is declared on the route with `can:reports.sales.view`; there is nothing extra to
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
            // Inclusive: a board for "today" carries everything recognised today, rather than
            // stopping at midnight.
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
