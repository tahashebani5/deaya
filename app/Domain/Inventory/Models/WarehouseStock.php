<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Actions\ApplyStockChange;
use App\Domain\Inventory\Actions\SetStockItemUnit;
use Database\Factories\WarehouseStockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * How much of one size is in one warehouse, right now.
 *
 * **`quantity` is not fillable, and that is the central rule of this context.** Every change to
 * it comes from {@see ApplyStockChange}, under a row lock, inside the transaction that also
 * writes the {@see StockMovement} explaining it. Making it mass-assignable would mean a payload
 * could move stock without leaving a reason behind — which is the one thing this whole design
 * exists to prevent.
 *
 * `low_stock_threshold` *is* fillable, because it is the opposite kind of thing: a preference
 * someone sets, not a fact the business observed.
 *
 * `unit` is not fillable: a snapshot of `stockItem->unit`, written once by
 * {@see ApplyStockChange::increase()} the first time this (warehouse, item) pair gets a balance.
 * Changed after that only by {@see SetStockItemUnit}, which is what
 * keeps every balance and batch for one shelf agreeing with each other and with
 * `stock_items.unit` — the same treatment `purchase_order_items.unit` gets on arrival, with one
 * further write path added deliberately for this one column.
 *
 * It used to snapshot `products.stock_unit`, reached through the variant this row was keyed on.
 * That column is gone: two products sharing one pile could each claim a different answer for it,
 * and whichever movement ran first decided what the balance meant.
 */
#[UseFactory(WarehouseStockFactory::class)]
#[Fillable(['low_stock_threshold'])]
class WarehouseStock extends Model
{
    /** @use HasFactory<WarehouseStockFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Strings, never floats. These are counted, compared and summed against a ledger,
            // and binary drift in a shelf count is a discrepancy nobody can ever explain.
            'quantity' => 'decimal:3',
            'low_stock_threshold' => 'decimal:3',
            'unit' => PricingUnit::class,
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The shelf this balance is of.
     *
     * **Same context, and that is the point of the move.** This used to reach across into Catalog
     * for a `ProductVariant` — the one cross-context relation in the codebase, carefully limited
     * to eager-loading a label because RULES.md §3 says decisions cross a boundary through a
     * Service. Stock is keyed on a {@see StockItem} now, which Inventory owns, so the exception is
     * no longer needed: the unit this row is checked against and the name it renders both come
     * from a model in this module.
     *
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * Whether this line has fallen to or below the level someone asked to be warned about.
     *
     * A null threshold is not "zero" — it means nobody asked, so nothing is low.
     */
    public function isLowStock(): bool
    {
        if ($this->low_stock_threshold === null) {
            return false;
        }

        return bccomp((string) $this->quantity, (string) $this->low_stock_threshold, 3) <= 0;
    }
}
