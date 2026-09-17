<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Enums\PricingUnit;

final readonly class ProductFilters
{
    public function __construct(
        /** Matches the name or the slug. */
        public ?string $search = null,
        /** The catalogue heading — «التصنيف». Sent as `product_category_id`. */
        public ?int $productCategoryId = null,
        public ?PricingUnit $pricingUnit = null,
        public ?PricingMode $pricingMode = null,
        /** null = both active and inactive. */
        public ?bool $isActive = null,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     */
    public static function fromArray(array $query): self
    {
        $search = isset($query['search']) ? trim((string) $query['search']) : '';

        return new self(
            search: $search !== '' ? $search : null,
            // `> 0` rather than a plain cast: `?product_category_id=` with nothing after it
            // reads as «كل التصنيفات», and casting it would filter on id zero and return
            // nothing at all.
            productCategoryId: ((int) ($query['product_category_id'] ?? 0)) > 0
                ? (int) $query['product_category_id']
                : null,
            pricingUnit: PricingUnit::tryFrom((string) ($query['pricing_unit'] ?? '')),
            pricingMode: PricingMode::tryFrom((string) ($query['pricing_mode'] ?? '')),
            isActive: array_key_exists('is_active', $query) && $query['is_active'] !== null
                ? filter_var($query['is_active'], FILTER_VALIDATE_BOOLEAN)
                : null,
        );
    }
}
