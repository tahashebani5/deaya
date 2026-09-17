<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\DTOs\OrderItemData;
use App\Domain\Order\Exceptions\ManualPriceRequired;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;

/**
 * Prices one line and writes it.
 *
 * Pricing and writing are one action rather than two because the price must never travel as a
 * loose array between them: `unit_price` and `line_total` are deliberately not fillable, so a
 * caller handed a bare array would have to remember to force-fill exactly the two keys a
 * request must never supply. Both callers — taking an order and replacing its lines — go
 * through here, so there is one answer to "what does this line cost and how does it get saved".
 *
 * **Listed prices always win.** For a product with price tiers the number comes from
 * `CatalogService::quote()` and a `unit_price` in the request is ignored, so a posted number can
 * never undercut an agreed rate. Only a product the catalogue itself says is priced «حسب الطلب»
 * takes a price from the clerk — and then it is required, because there is nothing to fall back
 * on.
 */
final class AddOrderItem
{
    public function __construct(private readonly CatalogService $catalog) {}

    /**
     * @throws ManualPriceRequired
     */
    public function __invoke(Order $order, OrderItemData $data): OrderItem
    {
        $product = $this->catalog->findProduct($data->productId);

        /** @var ProductVariant $variant */
        $variant = $product->variants->firstWhere('id', $data->productVariantId)
            ?? $product->variants()->with('priceTiers')->findOrFail($data->productVariantId);

        $unitPrice = $product->hasListedPrices()
            ? $this->catalog->quote($product, $variant, $data->quantity)->unitPrice
            : $this->manual($product, $data);

        /** @var OrderItem $item */
        $item = $order->items()->make([
            'product_id' => (int) $product->getKey(),
            'product_variant_id' => (int) $variant->getKey(),
            // Copied, not joined: renaming a product must not rewrite a finished invoice.
            'product_name' => $product->name,
            'variant_label' => $variant->label,
            'pricing_unit' => $product->pricing_unit,
            'quantity' => $data->quantity,
            'notes' => $data->notes,
            'sort_order' => $data->sortOrder,
        ]);

        // Never fillable: these come from the catalogue, and a request that could post them
        // could name its own price.
        //
        // The total is derived rather than multiplied out here, so the one rule about *which*
        // quantity an invoice is built on — see {@see OrderItem::billableQuantity()} — has a
        // single home. A line is born with nothing missing, so this is `quantity` today; it
        // stops being that the moment a shortage is recorded against it.
        // **The cost travels with the price, and for the same reason.** A وسيط size carries what
        // the vendor charges us; copying it here is what makes a later change to the catalogue
        // leave this order alone — see OUTSOURCED-PRODUCTS.md §6. Null for everything we make
        // ourselves, which is costed from what it consumed rather than from a typed number.
        //
        // Force-filled beside `unit_price` rather than made fillable: a request that could post
        // it could name its own margin.
        $item->forceFill([
            'unit_price' => $unitPrice,
            'unit_cost' => $variant->cost_price === null ? null : (string) $variant->cost_price,
        ]);
        $item->forceFill(['line_total' => $item->deriveLineTotal()])->save();

        return $item;
    }

    /**
     * @throws ManualPriceRequired
     */
    private function manual(Product $product, OrderItemData $data): ?string
    {
        if ($data->unitPrice !== null) {
            return $data->unitPrice;
        }

        // **A customer may leave it blank; a clerk may not.**
        //
        // The customer app cannot name a price — it is never sent one for a product priced
        // «حسب الطلب», and a client that could post one could buy at a price it invented. So a
        // request from the app arrives unpriced on purpose, and the shop quotes it on the move
        // that accepts it. Refusing here made a whole category of the catalogue — the
        // reinforced 3D bags, the cards — impossible to order from the app at all.
        //
        // Staff are still refused, and that is not an inconsistency: a clerk writing an order
        // at the counter *is* the person who names the price, and an order typed in with the
        // box left empty is a mistake rather than a request awaiting a quote.
        if ($data->allowsQuoteLater) {
            return null;
        }

        throw ManualPriceRequired::make($product->name);
    }
}
