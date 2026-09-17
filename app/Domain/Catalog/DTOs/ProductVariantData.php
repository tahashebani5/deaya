<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

final readonly class ProductVariantData
{
    /**
     * @param  list<PriceTierData>  $priceTiers  Empty for a quote-only product.
     */
    public function __construct(
        public string $label,
        /**
         * Which shelf this size draws from — null for a size that is never stocked.
         *
         * The one field on a variant that points outside Catalog. Two products at the same size
         * normally name the same one: كيس شحن سادة 25*35 and كيس شحن مطبوع 25*35 are one pile.
         */
        public ?int $stockItemId = null,
        /**
         * What this size costs us when a vendor makes it — «سعر التكلفة».
         *
         * Null everywhere but under a «وسيط» heading: goods we make ourselves are costed from
         * what they consumed, so a second number typed onto the catalogue would be an unowned
         * answer to a question `production_cost_entries` already answers. The request refuses it
         * elsewhere, which is why nothing here has to ask.
         */
        public ?string $costPrice = null,
        public ?int $widthCm = null,
        public ?int $heightCm = null,
        public bool $isActive = true,
        public int $sortOrder = 0,
        public array $priceTiers = [],
        /** Present when updating an existing variant. */
        public ?int $id = null,
    ) {}

    /**
     * @param  array<string, mixed>  $variant
     */
    public static function fromArray(array $variant): self
    {
        return new self(
            label: (string) $variant['label'],
            stockItemId: isset($variant['stock_item_id']) && $variant['stock_item_id'] !== ''
                ? (int) $variant['stock_item_id']
                : null,
            costPrice: isset($variant['cost_price']) && $variant['cost_price'] !== ''
                ? (string) $variant['cost_price']
                : null,
            widthCm: isset($variant['width_cm']) ? (int) $variant['width_cm'] : null,
            heightCm: isset($variant['height_cm']) ? (int) $variant['height_cm'] : null,
            isActive: (bool) ($variant['is_active'] ?? true),
            sortOrder: (int) ($variant['sort_order'] ?? 0),
            priceTiers: array_map(
                PriceTierData::fromArray(...),
                is_array($variant['price_tiers'] ?? null) ? $variant['price_tiers'] : [],
            ),
            id: isset($variant['id']) ? (int) $variant['id'] : null,
        );
    }
}
