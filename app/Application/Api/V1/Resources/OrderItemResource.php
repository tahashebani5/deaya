<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // The keys are here for opening the live catalogue entry; the names beside them are
            // the snapshot, and they are what an old order must display.
            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'product_name' => $this->product_name,
            'variant_label' => $this->variant_label,

            // The live catalogue row, for the card a reader taps to reach it: the code said out
            // loud, and the one photograph. Deliberately *not* part of the snapshot above — a
            // product renamed or rephotographed since shows its new face here while the invoice
            // keeps saying what was sold. Absent, never guessed at, when the relation was not
            // loaded; the primary image alone, because a line is a row and the gallery belongs
            // to the product screen.
            'product_code' => $this->whenLoaded('product', fn () => $this->product->code),
            'product_image' => $this->whenLoaded('product', function () {
                $image = $this->product->images->first();

                return $image === null ? null : new ProductImageResource($image);
            }),

            // What is missing from this line, in this line's own unit. Null until somebody has
            // counted, which is not the same as nothing being missing.
            'shortage_quantity' => $this->shortage_quantity === null
                ? null
                : (string) $this->shortage_quantity,

            // What the customer left on the counter, in the same unit, and what became of it.
            // Null on every line of every order delivered whole, which is nearly all of them —
            // «nothing recorded» rather than «nothing left», the distinction `shortage_quantity`
            // above already makes.
            //
            // The disposition travels as a value *and* a label because the screen prints one and
            // branches on the other, exactly as `pricing_unit` beside it does. It is read off
            // the line rather than re-derived from the product's heading: re-filing a product
            // must not rewrite a delivery recorded last March — see the migration that added
            // the pair.
            'undelivered_quantity' => $this->undelivered_quantity === null
                ? null
                : (string) $this->undelivered_quantity,
            'undelivered_disposition' => $this->undelivered_disposition?->value,
            'undelivered_disposition_label' => $this->undelivered_disposition?->label(),
            // In the **stock** unit, beside `stock_unit_label` below — never in the pricing unit
            // the two lines above are counted in. Null where nothing came back.
            'restocked_quantity' => $this->restocked_quantity === null
                ? null
                : (string) $this->restocked_quantity,

            // What those goods cost us, for the lines where they were a loss rather than a
            // return — see OrderItem::deliveryLoss(). Null for a restocked line, because bags
            // back on the shelf cost the shop nothing and their material cost was restated down
            // when they were credited back.
            //
            // Derived on the way out rather than stored, and from columns already on the row, so
            // this costs no query. The authoritative record is still the `delivery_loss`
            // production-cost entry the P&L sums; this is the cheap read for a screen.
            'delivery_loss' => $this->deliveryLoss(),

            'pricing_unit' => $this->pricing_unit->value,
            'pricing_unit_label' => $this->pricing_unit->label(),

            // Strings, never numbers: "1.100" survives a client's JSON parser as the price the
            // catalogue printed, where 1.1 has already stopped being it.
            'quantity' => (string) $this->quantity,

            // What the line is actually priced on: everything ordered, less whatever never
            // arrived, less whatever the customer did not take. Sent rather than left to the
            // client to subtract, because the rule about which quantity an invoice is built on
            // has one home — see OrderItem::billableQuantity(), and note that it now has two
            // subtrahends rather than one.
            'billable_quantity' => $this->billableQuantity(),

            // **Null, not `''`.** A line on a request for something priced «حسب الطلب» has no
            // price until the reviewer quotes it on the accept — see
            // {@see \App\Domain\Order\Actions\AddOrderItem}. Casting null to a string sent
            // an empty string to the app, which is neither a number nor an honest absence.
            'unit_price' => $this->unit_price === null ? null : (string) $this->unit_price,
            'line_total' => $this->line_total === null ? null : (string) $this->line_total,

            'notes' => $this->notes,
            'sort_order' => $this->sort_order,

            // Null unless the employee said the sales unit and the warehouse unit differ — see
            // the class docblock on OrderItem.
            'warehouse_quantity' => $this->warehouse_quantity === null ? null : (string) $this->warehouse_quantity,

            // What this line cost to produce, on the accrual side that mirrors `line_total` on
            // the revenue side — every one of the four null until the line has actually reached
            // printing. See DeductOrderStock and ApplyManufacturingRates.
            // **What a وسيط line costs us, and the two keys the order clerk never sees.**
            // `unit_cost` is the copy of `product_variants.cost_price` taken the day the order
            // was — which is what makes a later change to the catalogue leave this order alone —
            // and `outsourcing_cost` is what the line came to at «جاهزة». Behind
            // `products.view_cost`, the same grant that guards the catalogue number they came
            // from: a clerk who may not see what the shop pays for the goods may not see it here
            // either, and hiding it on one screen while sending it on another would be a lock on
            // one door of two.
            'unit_cost' => $this->when(
                $request->user()?->can(PermissionName::ViewProductCost->value) === true,
                fn () => $this->unit_cost === null ? null : (string) $this->unit_cost,
            ),
            'outsourcing_cost' => $this->when(
                $request->user()?->can(PermissionName::ViewProductCost->value) === true,
                fn () => $this->outsourcing_cost === null ? null : (string) $this->outsourcing_cost,
            ),

            // What this line **paid** for its material. On a printed line that drew an
            // investor's plain stock that is سعر السادة × weight, not what the goods cost — the
            // press bought them. `material_cost_actual` beside it is what they cost.
            'material_cost' => $this->material_cost === null ? null : (string) $this->material_cost,
            'material_cost_actual' => $this->material_cost_actual === null
                ? null
                : (string) $this->material_cost_actual,
            'stock_purchased_at' => $this->stock_purchased_at?->toISOString(),
            'labor_cost' => $this->labor_cost === null ? null : (string) $this->labor_cost,
            'overhead_cost' => $this->overhead_cost === null ? null : (string) $this->overhead_cost,
            'cogs' => $this->cogs === null ? null : (string) $this->cogs,

            // The rate behind `material_cost`: what one unit off the shelf cost — see
            // OrderItem::unitMaterialCost(). Derived on the way out rather than stored, and sent
            // rather than left to the client to divide, for the reason `billable_quantity` is:
            // the arithmetic has one home, and a division done in a phone's doubles is how
            // 1234.56 / 3 reaches a screen as 411.51999999999998.
            'unit_material_cost' => $this->unitMaterialCost(),

            // **The unit that figure is *per*, and it is the shelf's, not the line's.** A run
            // sold by the piece can be stocked by the kilo, and «تكلفة القطعة ٨٫٠٠٠» said of a
            // per-kilogram rate is a wrong number rather than an imprecise one. Absent when the
            // shelf behind the line was not loaded — a list payload carries no costs to label.
            'stock_unit_label' => $this->when(
                $this->relationLoaded('variant') && ($this->variant?->relationLoaded('stockItem') ?? false),
                fn () => $this->stockUnit()->label(),
            ),
        ];
    }
}
