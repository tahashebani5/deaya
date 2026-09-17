<?php

declare(strict_types=1);

namespace App\Domain\Order\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\SetStockItemUnit;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Order\Actions\DeductOrderStock;
use App\Domain\Order\Actions\RecordPartialDelivery;
use App\Domain\Order\Actions\ResolveOrderFlow;
use App\Domain\Order\Enums\UndeliveredDisposition;
use App\Domain\Order\Support\Money;
use App\Domain\Order\Support\TransitionFields;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line of an order, priced at what it cost on the day.
 *
 * The name, the label and the unit are copied rather than joined: renaming a product must not
 * rewrite an invoice issued last year. `unit_price` and `line_total` are not fillable — both
 * come from `CatalogService::quote()` by way of the action, never from a request.
 *
 * `warehouse_quantity` is the exception to "nothing here is typed by a clerk without the
 * catalogue's say-so": there is no catalogue rule that converts a sales unit into a warehouse
 * unit, so an employee reads it off a scale and types the total for the whole line directly —
 * not a per-piece factor multiplied out, because a batch is weighed together, not counted. Null
 * is the common case — see {@see DeductOrderStock}, which deducts `quantity` unchanged when
 * absent.
 *
 * **It is asked for on the way into «جاهزة», not when the order is taken.** A clerk agreeing
 * «٥٠٠ قطعة» with a customer on the phone has not been near a scale, and the parcel does not
 * exist yet; the foreman who shelves it has both. See {@see TransitionFields}.
 *
 * Audited; its entries are read through the order that owns it.
 */
#[UseFactory(OrderItemFactory::class)]
#[Fillable([
    'product_id', 'product_variant_id', 'product_name', 'variant_label', 'pricing_unit',
    'quantity', 'notes', 'sort_order', 'warehouse_quantity',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pricing_unit' => PricingUnit::class,
            // Strings all the way: a quantity is multiplied by a price, and that is precisely
            // where a float stops being the number the catalogue printed.
            'quantity' => 'decimal:3',
            // What is missing from this line, in this line's own unit. Null until somebody has
            // counted — «nothing recorded» is not «nothing missing».
            'shortage_quantity' => 'decimal:3',
            // What the customer left on the counter, in this line's own unit, and what became of
            // it — see the migration that added the pair, and {@see RecordPartialDelivery}, their
            // only writer. Both null or both set; never one of the two.
            'undelivered_quantity' => 'decimal:3',
            'undelivered_disposition' => UndeliveredDisposition::class,
            // How much of that leftover actually reached a shelf, in the **stock** unit — which
            // is a different figure from the one above whenever the two units differ. Null for a
            // line nothing came back from, printed ones included.
            'restocked_quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
            // The copy of what this size cost us on the day — see the migration that added it.
            // Three places, like the price it sits beside, so the two round the same way.
            'unit_cost' => 'decimal:3',
            'line_total' => 'decimal:2',
            // Null means "same unit as the warehouse" — see the class docblock.
            'warehouse_quantity' => 'decimal:3',
            // When the press bought this line's plain material off the shelf — see the migration
            // that added it, and {@see isPrinted()} for who is entitled to sell it.
            'stock_purchased_at' => 'datetime',
        ];
    }

    /**
     * Whether the press actually runs on this line — the fork the whole of سعر السادة turns on.
     *
     * **Asked of the line, never of the order.** {@see ResolveOrderFlow}
     * puts a whole order on the printing road for one printed line among five plain ones, which
     * is right for the road and wrong for the money: an order-level answer would have the plain
     * lines inside a printed order buying their own material at a price that has nothing to do
     * with them. What a line is, is a fact about the line.
     *
     * The unknown case is `in_house`, the same answer `ResolveOrderFlow` gives it and for the
     * same reason: a product filed under no heading is production work until somebody says
     * otherwise. That is the generous direction here too — it prices the material at what the
     * press pays, which is the figure the investor was promised.
     *
     * Callers must eager-load `product.productCategory.parent`; strict mode turns a forgotten
     * load into an exception rather than a query per line.
     */
    public function isPrinted(): bool
    {
        return $this->productionMode() === ProductionMode::InHouse;
    }

    /**
     * How the goods on this line came to exist — the heading's answer, copied down to the line.
     *
     * **Extracted from {@see isPrinted()} rather than added beside it**, because a second reader
     * appeared that needs all three modes and not the boolean.
     * {@see UndeliveredDisposition::forItem()} asks «can anybody else buy these?», and وسيط
     * answers no while `isPrinted()` answers false — the one case where the two questions come
     * apart. Left as one method, the disposition would have quietly sent a vendor's printed bags
     * back to a shelf they were never on.
     *
     * The unknown case is `in_house`, the same answer `ResolveOrderFlow` gives and for the same
     * reason: a product filed under no heading is production work until somebody says otherwise.
     *
     * Callers must eager-load `product.productCategory.parent`; strict mode turns a forgotten
     * load into an exception rather than a query per line.
     */
    public function productionMode(): ProductionMode
    {
        return $this->product?->productCategory?->productionMode() ?? ProductionMode::InHouse;
    }

    /**
     * What this line is actually charged for: everything ordered, less whatever never arrived,
     * less whatever the customer did not take.
     *
     * **The one place a quantity becomes money.** `quantity` is what the customer asked for and
     * never moves — an order that overwrote it would lose the question the other two are the
     * answers to — so the number the invoice is built on is derived here instead, and every
     * caller that prices a line goes through it. That is also what makes the money reversible
     * without a reversing entry: put either subtrahend back to null and this returns the number
     * it was, because it was never a written balance.
     *
     * **Two subtrahends, and they are different facts about different moments.**
     * `shortage_quantity` is what we never had — recorded at «نواقص», while the lines are
     * still editable and the goods still expected. `undelivered_quantity` is what was made,
     * counted and then left on the counter — recorded at «تم الاستلام», long after the lines
     * lock. They can both be non-null on one line and neither cancels the other out: an order
     * short fifty of five hundred, whose customer then took only three hundred of the four
     * hundred and fifty that existed, is charged for three hundred.
     *
     * Floored at zero. The bound belongs to validation — and `TransitionFields` caps the
     * delivered box at this very figure so the two can never sum past the quantity — but a
     * negative line total is bad enough that the arithmetic refuses it too.
     */
    public function billableQuantity(): string
    {
        $billable = bcsub(
            bcsub((string) $this->quantity, (string) ($this->shortage_quantity ?? '0'), 3),
            (string) ($this->undelivered_quantity ?? '0'),
            3,
        );

        return bccomp($billable, '0', 3) < 0 ? '0.000' : $billable;
    }

    /**
     * What the goods this customer left behind cost us — null unless they were a loss.
     *
     * **A read for a screen, not the record itself.** The authoritative row is the
     * `delivery_loss` {@see ProductionCostEntry} that {@see RecordPartialDelivery} writes:
     * reversible, audited, and what the profit-and-loss statement actually sums. This is the
     * same figure derived from three columns already on the line, so an order screen can print
     * it without a query — exactly the arrangement
     * {@see unitMaterialCost()} already has.
     *
     * **Null for a restocked line, and that is the point of asking the disposition first.** Bags
     * that went back on the shelf cost the shop nothing; their material cost was restated down
     * when they were credited back, so a figure here would be a loss that both did not happen
     * and has already been un-charged.
     *
     * Null too while `cogs` is unknown — a line that never reached «جاهزة» has no cost to take
     * a share of, and a zero would read as «these goods were free».
     *
     * Two decimals, like the money columns it is derived from and unlike
     * {@see unitMaterialCost()}, which is a rate.
     */
    /**
     * How much of this line's draw the customer left behind, in the **shelf's** unit.
     *
     * **Two units, one of which has no conversion — so there are two answers.** A line sold and
     * stocked the same way converts exactly: three hundred bags left of five hundred is three
     * hundred bags off the shelf. A line sold by the piece and stocked by the kilo has no
     * meaningful per-piece weight — {@see DeductOrderStock} refuses to multiply one out, because
     * bags weighed together do not have one — so what comes back is a **pro-rata of the weight
     * that actually left**, which is a suggestion for somebody standing at a scale rather than a
     * figure to act on unread.
     *
     * That is exactly how {@see TransitionFields} uses it: as the pre-filled value of the box
     * that asks the storekeeper what went back, on the lines where the two units differ. Where
     * they agree nothing is asked and this is used as it stands.
     *
     * Zero when nothing was left behind, which keeps {@see deliveredStockQuantity()} total.
     */
    public function undeliveredStockQuantity(): string
    {
        $left = (string) ($this->undelivered_quantity ?? '0');

        if (bccomp($left, '0', 3) <= 0) {
            return '0.000';
        }

        if (! $this->isStockedInAnotherUnit()) {
            return $left;
        }

        $ordered = (string) $this->quantity;

        // A line of zero cannot have anything left over; the guard is here because the division
        // is, not because the domain can reach it.
        if (bccomp($ordered, '0', 3) <= 0) {
            return '0.000';
        }

        return bcdiv(bcmul($this->producedQuantity(), $left, 8), $ordered, 3);
    }

    /**
     * What should remain drawn from the shelf once the customer's leavings are put back — the
     * figure {@see RedrawOrderLineStock} re-draws.
     *
     * The complement of {@see undeliveredStockQuantity()} against what actually left, so the two
     * always sum to {@see producedQuantity()} and no part of a draw can go missing between them.
     *
     * Floored at zero, for the same reason {@see billableQuantity()} is: the bound belongs to
     * validation, and a negative quantity handed to the warehouse is bad enough that the
     * arithmetic refuses it too.
     */
    public function deliveredStockQuantity(): string
    {
        $kept = bcsub($this->producedQuantity(), $this->undeliveredStockQuantity(), 3);

        return bccomp($kept, '0', 3) < 0 ? '0.000' : $kept;
    }

    public function deliveryLoss(): ?string
    {
        if ($this->undelivered_disposition !== UndeliveredDisposition::WrittenOff
            || $this->cogs === null) {
            return null;
        }

        $ordered = (string) $this->quantity;

        // Unreachable through the domain — a line of zero cannot have something left over — but
        // the division is here and a guard costs less than the day it is not.
        if (bccomp($ordered, '0', 3) <= 0) {
            return null;
        }

        return Money::round(bcdiv(
            bcmul((string) $this->cogs, (string) ($this->undelivered_quantity ?? '0'), 8),
            $ordered,
            8,
        ));
    }

    /**
     * What the line costs at the price it was agreed at.
     *
     * **`unit_price` is never re-quoted for the smaller quantity.** A run of 300 earned the
     * 300-tier rate; delivering 200 of it is our failure, and looking the price up again would
     * charge the customer *more* per bag because we came up short.
     */
    public function deriveLineTotal(): ?string
    {
        // **No price, no total — and deliberately not zero.** A line priced «حسب الطلب» that
        // nobody has quoted yet has no number, and inventing 0.00 here would make it
        // indistinguishable from a line given away free. See the migration that made these
        // columns nullable.
        if ($this->unit_price === null) {
            return null;
        }

        return Money::round(bcmul((string) $this->unit_price, $this->billableQuantity(), 6));
    }

    /**
     * Whether this line has a price at all.
     *
     * Only ever false on an order still sitting in «بانتظار المراجعة»: the move that accepts a
     * request collects the missing prices and is refused without them.
     */
    public function isPriced(): bool
    {
        return $this->unit_price !== null;
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * The ledger row this line's own stock deduction produced. Null until the line reaches
     * printing; read back by `ReverseOrderStockDeduction` to credit the exact cost layers it
     * drew from if the order is later cancelled.
     *
     * @return BelongsTo<StockMovement, $this>
     */
    public function fulfillmentStockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /**
     * The unit this line's stock is counted in on the shelf.
     *
     * **The shelf's, not the line's, and not the product's any more.** This used to read
     * `products.stock_unit`; that column is gone, and the unit now belongs to the
     * {@see StockItem} the size draws on — see
     * {@see SetStockItemUnit}. The move is the whole point of
     * stock items: «كيس شحن سادة» and «كيس شحن مطبوع» at one size are one pile, and while each
     * product answered this question separately the two could insist that one heap was counted
     * two different ways.
     *
     * `pricing_unit` is untouched by any of it — what the customer was billed in — and the two
     * still need not agree, which is exactly what {@see isStockedInAnotherUnit()} is about.
     *
     * Falls back to the selling unit when the variant, its shelf, or the link between them is
     * missing — a quote-only size is never stocked, and an invoice keeps its own copy of
     * everything it needs. Guessing that the units *differ* would be the worse of the two
     * mistakes: it would demand a measurement for a line that needs none.
     */
    public function stockUnit(): PricingUnit
    {
        return $this->variant?->stockItem?->unit ?? $this->pricing_unit;
    }

    /**
     * Whether this line leaves the shelf in a unit other than the one it was sold in.
     *
     * **The whole reason {@see $warehouse_quantity} has to be asked for.** «٥٠٠ قطعة» sold off a
     * shelf counted in kilograms has no automatic answer: bags of one size differ in weight,
     * which is why that shelf is weighed rather than counted, and no factor the catalogue holds
     * converts the one into the other. A line whose units agree needs nothing typed — what was
     * sold is what leaves.
     */
    public function isStockedInAnotherUnit(): bool
    {
        return $this->stockUnit() !== $this->pricing_unit;
    }

    /**
     * What this line actually produced, physically — the same number {@see DeductOrderStock}
     * takes out of the warehouse.
     *
     * **The one basis every production-side cost is computed against.** Material cost is a FIFO
     * draw of exactly this quantity; manufacturing rates (see `ApplyManufacturingRates`) are
     * applied against it too — never against `quantity` directly when `warehouse_quantity` is
     * set, or a line's `cogs` would sum two costs computed on different physical amounts for the
     * same line.
     */
    public function producedQuantity(): string
    {
        return $this->warehouse_quantity === null
            ? (string) $this->quantity
            : (string) $this->warehouse_quantity;
    }

    /**
     * What one unit off the shelf cost in material — «كم تكلفتنا القطعة؟» answered.
     *
     * **Derived, never stored, and never a column.** {@see $material_cost} is a FIFO draw of the
     * cost layers this line actually consumed, so the rate behind it is that draw over what left
     * the warehouse — and a line that ate two layers at different prices has a weighted average
     * no single `stock_batches.unit_cost` row states. Storing it would be a second answer to a
     * question `material_cost` and {@see producedQuantity()} already answer together.
     *
     * **Per {@see producedQuantity()}, so the figure is in the *shelf's* unit** — see
     * {@see stockUnit()}. 300 bags weighing 12.5 kilograms together cost what those kilograms
     * cost; dividing by 300 would invent a per-bag material cost out of a scale reading, which is
     * the very conversion COST-TRACKING-UNIT-CONVERSION.md §4 refuses to make. Whoever draws this
     * must say the unit beside it.
     *
     * Three decimals, matching `unit_price` rather than the two-place money columns: the two
     * rates are read in one glance, and a rate rounded to piastres is 0.00 on a bag.
     *
     * Null when the line has not been costed — and null too when nothing left the shelf, which
     * is the only division there is no answer to.
     */
    public function unitMaterialCost(): ?string
    {
        if ($this->material_cost === null) {
            return null;
        }

        $produced = $this->producedQuantity();

        return bccomp($produced, '0', 3) <= 0
            ? null
            : bcdiv((string) $this->material_cost, $produced, 3);
    }
}
