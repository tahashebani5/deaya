<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Actions\SetStockItemUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Declares what a shelf is counted in — see
 * {@see SetStockItemUnit}.
 *
 * Its own endpoint rather than a field on the update, because it is a different kind of change:
 * every balance and cost layer snapshotted against this item is restamped in the same
 * transaction, under the same locks a stock movement takes. Replaces the old
 * `PATCH /products/{product}/stock-unit`, which asked a product a question that belonged to the
 * pile — two products sharing one shelf could each give a different answer.
 */
class SetStockItemUnitRequest extends FormRequest
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
            'unit' => ['required', Rule::enum(PricingUnit::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit.required' => 'وحدة التخزين مطلوبة',
            'unit.enum' => 'وحدة التخزين غير صحيحة',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'unit' => 'وحدة التخزين',
        ];
    }
}
