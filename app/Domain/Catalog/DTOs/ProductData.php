<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use App\Domain\Catalog\Actions\SyncProductVariants;
use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Enums\PricingUnit;

final readonly class ProductData
{
    /**
     * @param  list<string>|null  $features
     * @param  list<ProductVariantData>|null  $variants  null means "not supplied" — on update the
     *                                                   existing variants are left untouched.
     */
    public function __construct(
        /** null means "not supplied": the model derives one from the name and the code. */
        public ?string $slug,
        public string $name,
        /** The catalogue heading it sits under — «التصنيف». Required from every request. */
        public int $productCategoryId,
        /**
         * What the product is made of — «التصنيف». Optional.
         *
         * Naming it means every size the product carries resolves to the shelf of that material
         * at the same size, creating it if the material has not reached it yet. See
         * {@see SyncProductVariants::resolveStockItemId()}.
         *
         * `null` means **not supplied**, exactly as it does for `slug` and `isActive` below: on
         * update the current material is kept. There is deliberately no way to *clear* it through
         * this endpoint — doing so would silently detach every size from its shelf on the next
         * save, and that is not a thing to make easy by accident.
         */
        public ?int $stockItemGroupId,
        public PricingUnit $pricingUnit,
        public PricingMode $pricingMode,
        public string $minOrderQuantity,
        public ?string $description = null,
        public ?array $features = null,
        /** null means "not supplied": on update the current value is kept. */
        public ?bool $isActive = null,
        public int $sortOrder = 0,
        public ?array $variants = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $pricingUnit = PricingUnit::from((string) $validated['pricing_unit']);

        return new self(
            slug: isset($validated['slug']) && $validated['slug'] !== ''
                ? (string) $validated['slug']
                : null,
            name: (string) $validated['name'],
            productCategoryId: (int) $validated['product_category_id'],
            stockItemGroupId: isset($validated['stock_item_group_id']) && $validated['stock_item_group_id'] !== ''
                ? (int) $validated['stock_item_group_id']
                : null,
            pricingUnit: $pricingUnit,
            pricingMode: PricingMode::from((string) $validated['pricing_mode']),
            minOrderQuantity: (string) ($validated['min_order_quantity'] ?? '1'),
            description: isset($validated['description']) && $validated['description'] !== ''
                ? (string) $validated['description']
                : null,
            features: is_array($validated['features'] ?? null) ? array_values($validated['features']) : null,
            isActive: array_key_exists('is_active', $validated) && $validated['is_active'] !== null
                ? (bool) $validated['is_active']
                : null,
            sortOrder: (int) ($validated['sort_order'] ?? 0),
            variants: array_key_exists('variants', $validated) && is_array($validated['variants'])
                ? array_map(ProductVariantData::fromArray(...), $validated['variants'])
                : null,
        );
    }
}
