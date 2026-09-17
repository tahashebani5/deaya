<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Enums\ManufacturingCostType;

final readonly class ManufacturingCostRateFilters
{
    public function __construct(
        public ?int $productId = null,
        public ?int $productVariantId = null,
        public ?ManufacturingCostType $costType = null,
        /** null = both. */
        public ?bool $isActive = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query): self
    {
        return new self(
            productId: isset($query['product_id']) && $query['product_id'] !== ''
                ? (int) $query['product_id'] : null,
            productVariantId: isset($query['product_variant_id']) && $query['product_variant_id'] !== ''
                ? (int) $query['product_variant_id'] : null,
            costType: isset($query['cost_type']) && $query['cost_type'] !== ''
                ? ManufacturingCostType::from((string) $query['cost_type']) : null,
            isActive: array_key_exists('is_active', $query) && $query['is_active'] !== null && $query['is_active'] !== ''
                ? filter_var($query['is_active'], FILTER_VALIDATE_BOOLEAN)
                : null,
        );
    }
}
