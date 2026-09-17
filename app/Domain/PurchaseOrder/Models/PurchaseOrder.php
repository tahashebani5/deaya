<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\PurchaseOrderService;
use App\Domain\Vendor\Models\StockArrival;
use App\Domain\Vendor\Models\Vendor;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An order placed with a vendor for stock that has not arrived yet.
 *
 * `new → arrived → completed`, with `cancelled` reachable from either open status — see
 * {@see PurchaseOrderStatus}. `vendor_id`, `warehouse_id` and `status` are deliberately absent
 * from the fillable list: the first two are identity fields assigned once by
 * {@see CreatePurchaseOrder}, and the third only ever changes through a guarded Action, never
 * through mass assignment — the same rule `StockArrival::vendor_id` and `warehouse_id` follow.
 */
#[UseFactory(PurchaseOrderFactory::class)]
#[Fillable(['order_date', 'expected_date', 'notes'])]
class PurchaseOrder extends Model implements HasAuditTrail
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            // Not fillable — see RecalculatePurchaseOrderTotal, the only writer for both.
            'total_amount' => 'decimal:2',
            'total_additional_cost' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Where the order will be put away once it arrives.
     *
     * Read-only and for rendering only — the same shape `StockArrival::warehouse()` uses.
     * Nothing here writes a balance; that happens through
     * `InventoryService::recordMovement()`, reached via `ReceivePurchaseOrder`.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<PurchaseOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Order-level costs not tied to any single line — delivery, unloading, customs. Summed into
     * `total_additional_cost` and distributed across {@see items()} by
     * {@see AllocatePurchaseOrderAdditionalCosts}.
     *
     * @return HasMany<PurchaseOrderAdditionalCost, $this>
     */
    public function additionalCosts(): HasMany
    {
        return $this->hasMany(PurchaseOrderAdditionalCost::class);
    }

    /**
     * Every shipment posted against this order. Read-only, for rendering — this module already
     * depends on Vendor (see {@see PurchaseOrderService}), so the
     * relation belongs here rather than as a back-reference on `StockArrival`, which would close
     * a dependency loop RULES.md §3 forbids.
     *
     * @return HasMany<StockArrival, $this>
     */
    public function stockArrivals(): HasMany
    {
        return $this->hasMany(StockArrival::class);
    }

    /**
     * An order's history includes its lines — a document with three sizes on it is one story,
     * not four, and a line has no screen of its own to carry its own history. The same shape
     * `StockArrival::auditTrailSubjects()` uses.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [
            $this->getMorphClass() => [$this->getKey()],
            (new PurchaseOrderItem)->getMorphClass() => $this->items()->withTrashed()->pluck('id')->all(),
            (new PurchaseOrderAdditionalCost)->getMorphClass() => $this->additionalCosts()->withTrashed()->pluck('id')->all(),
        ];
    }
}
