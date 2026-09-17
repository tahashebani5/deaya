<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\PriceQuote;
use App\Domain\Catalog\Exceptions\NoPriceForQuantity;
use App\Domain\Catalog\Exceptions\ProductRequiresManualQuote;
use App\Domain\Catalog\Exceptions\QuantityBelowMinimum;
use App\Domain\Catalog\Exceptions\VariantDoesNotBelongToProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;

/**
 * Works out what a quantity of a variant costs.
 *
 * This is the single place pricing is decided. When orders arrive they must call this rather
 * than re-deriving a price, so a quote shown to a customer and the line written to an order can
 * never disagree.
 *
 * All arithmetic goes through bcmath on decimal strings. A total is a price multiplied by a
 * quantity, and floats lose exactness at precisely that step — 1.10 × 300 is 330, not
 * 330.00000000000006.
 */
final class QuoteProductPrice
{
    private const SCALE = 3;

    /**
     * @throws VariantDoesNotBelongToProduct
     * @throws ProductRequiresManualQuote
     * @throws QuantityBelowMinimum
     * @throws NoPriceForQuantity
     */
    public function __invoke(Product $product, ProductVariant $variant, string $quantity): PriceQuote
    {
        if ((int) $variant->product_id !== (int) $product->getKey()) {
            throw VariantDoesNotBelongToProduct::make((int) $variant->getKey(), (int) $product->getKey());
        }

        // Refuse before anything else: for a quote-only product there is no number to return,
        // and guessing one would be a commitment nobody made.
        if (! $product->hasListedPrices()) {
            throw ProductRequiresManualQuote::make($product->name);
        }

        if (! $product->meetsMinimumOrder($quantity)) {
            throw QuantityBelowMinimum::make(
                $quantity,
                (string) $product->min_order_quantity,
                $product->pricing_unit,
            );
        }

        $tier = $variant->priceTierFor($quantity);

        if ($tier === null) {
            throw NoPriceForQuantity::make($variant->label, $quantity);
        }

        $nextTier = $this->nextTierAfter($variant, $tier);

        return new PriceQuote(
            quantity: $this->normalise($quantity),
            unit: $product->pricing_unit,
            unitPrice: $this->normalise((string) $tier->unit_price),
            total: bcmul((string) $tier->unit_price, $quantity, self::SCALE),
            appliedTierMinQuantity: $this->normalise((string) $tier->min_quantity),
            nextTierMinQuantity: $nextTier ? $this->normalise((string) $nextTier->min_quantity) : null,
            nextTierUnitPrice: $nextTier ? $this->normalise((string) $nextTier->unit_price) : null,
            quantityToNextTier: $nextTier
                ? bcsub((string) $nextTier->min_quantity, $quantity, self::SCALE)
                : null,
        );
    }

    /**
     * The cheapest break the customer has not reached yet, so the app can nudge toward it.
     */
    private function nextTierAfter(ProductVariant $variant, ProductPriceTier $applied): ?ProductPriceTier
    {
        return $variant->priceTiers
            ->filter(fn (ProductPriceTier $tier) => bccomp(
                (string) $tier->min_quantity,
                (string) $applied->min_quantity,
                self::SCALE,
            ) > 0)
            ->sortBy(fn (ProductPriceTier $tier) => (float) $tier->min_quantity)
            ->first();
    }

    /**
     * Pads to a fixed scale so every value in a quote is formatted the same way and a client
     * never has to guess whether "1.1" and "1.100" are the same price.
     */
    private function normalise(string $value): string
    {
        return bcadd($value, '0', self::SCALE);
    }
}
