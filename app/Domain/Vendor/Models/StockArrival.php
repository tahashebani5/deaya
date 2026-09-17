<?php

declare(strict_types=1);

namespace App\Domain\Vendor\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Vendor\Actions\RecordStockArrival;
use App\Domain\Vendor\Actions\ReverseStockArrival;
use Database\Factories\StockArrivalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shipment received from a vendor — the paperwork behind one or more `StockMovement` rows.
 *
 * **Nothing updates or deletes one, and there is no route that does.** Editing it after the
 * balances it produced have already moved would let a warehouse's count and its own paperwork
 * disagree — the same reason `StockMovement` itself is append-only.
 *
 * **Except that it may be marked as having been entered in error.** `reversed_at`,
 * `reversed_by` and `reversal_reason` are stamped on by {@see ReverseStockArrival} within the
 * window that action guards, and what actually leaves the shelf is a further `arrival_reversal`
 * movement per line — the document is annotated, never rewritten and never removed. A receipt
 * that was reversed is re-entered as a *new* arrival; nothing un-reverses this one.
 *
 * `vendor_id`, `warehouse_id` and `received_by` are deliberately absent from the fillable list.
 * They come from the route and the authenticated user, never from the payload, the same rule
 * `StockMovement::employee_id` follows — see {@see RecordStockArrival}.
 *
 * `purchase_order_id` is nullable — most arrivals are unplanned — and deliberately has **no**
 * Eloquent relation here, unlike `vendor()`/`warehouse()` above. `App\Domain\PurchaseOrder`
 * already depends on this module (`ReceivePurchaseOrder` calls `VendorService`); a relation on
 * this side pointing back at it would import that namespace into this one and close the loop
 * RULES.md §3 forbids. `PurchaseOrder::stockArrivals()` holds the relation instead — the same
 * column, read from the side that is already allowed to know about the other.
 */
#[UseFactory(StockArrivalFactory::class)]
#[Fillable(['invoice_number', 'notes'])]
class StockArrival extends Model implements HasAuditTrail
{
    /** @use HasFactory<StockArrivalFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Null on every receipt anybody got right, which is nearly all of them.
            'reversed_at' => 'datetime',
        ];
    }

    /** Whether this receipt has already been undone. */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * Where the shipment was put away.
     *
     * Read-only and for rendering only — the same shape `WarehouseStock::stockItem()` and
     * `StockMovement::employee()` already use for a cross-context relation. Actually moving stock
     * goes through `InventoryService::recordMovement()`, never through this relation.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Who physically received it. Read-only, for rendering — see the note on {@see self::warehouse()}.
     *
     * @return BelongsTo<User, $this>
     */
    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Who undid it, on the receipt that was entered in error. Read-only, for rendering — see the
     * note on {@see self::warehouse()}.
     *
     * @return BelongsTo<User, $this>
     */
    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * @return HasMany<StockArrivalItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockArrivalItem::class);
    }

    /**
     * An arrival's history includes its lines — a document with three sizes on it is one story,
     * not four, and a line has no screen of its own to carry its own history.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [
            $this->getMorphClass() => [$this->getKey()],
            (new StockArrivalItem)->getMorphClass() => $this->items()->withTrashed()->pluck('id')->all(),
        ];
    }
}
