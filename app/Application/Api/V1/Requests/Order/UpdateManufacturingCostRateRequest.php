<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use App\Domain\Order\Enums\ManufacturingCostType;
use Illuminate\Validation\Rule;

/**
 * Same shape as creating; the uniqueness check excludes the rate's own row, via the route-bound
 * model {@see StoreManufacturingCostRateRequest::uniquePerCostType()} already reads. The mutual-
 * exclusivity check in {@see StoreManufacturingCostRateRequest::withValidator()} is inherited
 * unchanged — it is not specific to creating.
 *
 * Written out in full rather than merged onto the parent's — Scramble reads `rules()` statically
 * and `array_merge(parent::rules(), …)` publishes no request body at all. See RULES.md §7.
 */
class UpdateManufacturingCostRateRequest extends StoreManufacturingCostRateRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => [
                'nullable', 'integer',
                Rule::exists('products', 'id')->whereNull('deleted_at'),
            ],
            'product_variant_id' => [
                'nullable', 'integer',
                Rule::exists('product_variants', 'id')->whereNull('deleted_at'),
            ],
            'cost_type' => ['required', Rule::enum(ManufacturingCostType::class), $this->uniquePerCostType()],
            'rate_per_unit' => ['required', 'numeric', 'gte:0', 'max:999999999.999'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
