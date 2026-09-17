<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Models\StockItem;
use Database\Factories\PurchaseOrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One line inside a {@see PurchaseOrder} — a size, how much was ordered, and how much of it has
 * arrived so far.
 *
 * No `/logs` route of its own — like `StockArrivalItem`, its history is read through the
 * document that owns it, via `PurchaseOrder::auditTrailSubjects()`.
 *
 * `quantity_received` is not fillable and is never touched by a request directly: it is
 * incremented only by {@see ReceivePurchaseOrder}, once a {@see StockArrival} has actually
 * posted the stock it describes — the same rule that keeps `StockArrivalItem::stock_movement_id`
 * out of the fillable list.
 *
 * `base_total_cost` is typed by whoever raised the order — there is no catalogue price for what
 * *we* pay a vendor. Everything else cost-shaped on this line is derived from it, never fillable,
 * and computed in exactly one place, {@see AllocatePurchaseOrderAdditionalCosts}:
 * `base_unit_cost` (`base_total_cost / quantity_ordered`), `allocated_additional_cost` (this
 * line's proportional share of the order's {@see PurchaseOrder::additionalCosts()}), and
 * `final_unit_cost`/`final_total_cost` (the base figures plus that share — the landed cost,
 * which is what {@see ReceivePurchaseOrder} carries onto a stock arrival).
 *
 * `unit` is not fillable either: a snapshot of `stockItem->unit`, taken when
 * the line is created and never re-derived — for rendering only, the same treatment
 * `PurchaseOrder::warehouse()` documents.
 */
#[UseFactory(PurchaseOrderItemFactory::class)]
#[Fillable(['quantity_ordered', 'base_total_cost'])]
class PurchaseOrderItem extends Model
{
    /** @use HasFactory<PurchaseOrderItemFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Strings, never floats — the same reasoning `StockArrivalItem::quantity` carries:
            // these numbers are compared and summed against each other, and binary drift here is
            // a discrepancy nobody could ever explain.
            'quantity_ordered' => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'base_total_cost' => 'decimal:2',
            'base_unit_cost' => 'decimal:3',
            'allocated_additional_cost' => 'decimal:2',
            'final_unit_cost' => 'decimal:3',
            'final_total_cost' => 'decimal:2',
            'unit' => PricingUnit::class,
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * The size on order. Read-only, for rendering — see the note on
     * {@see PurchaseOrder::warehouse()} for why this context holds the relation but never
     * decides anything from it.
     *
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
