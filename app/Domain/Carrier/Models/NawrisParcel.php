<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Order\Models\Order;
use Database\Factories\NawrisParcelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One handover to Nawris: a code, a destination, and a sum to collect.
 *
 * Everything on it is a snapshot of what was actually sent, so an edit can replay creation
 * exactly — see the migration for why re-deriving any of it later is a bug rather than a saving.
 *
 * **Nothing here is fillable.** Every column is either assigned by us at dispatch or written from
 * a webhook, and neither of those is a request body. A mass-assignable `code` or
 * `amount_to_collect` would let a payload claim a parcel exists, or that it was asked to collect
 * something it was not.
 */
#[UseFactory(NawrisParcelFactory::class)]
#[Fillable([])]
class NawrisParcel extends Model
{
    /** @use HasFactory<NawrisParcelFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Strings, not floats: these are compared against order totals, and a comparison
            // between a decimal and a float is a coin toss.
            'amount_to_collect' => 'decimal:2',
            'delivery_price_deducted' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'remote_status_code' => 'integer',
            'conflict_raised_at' => 'datetime',
            'conflict_resolved_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<NawrisParcelOrder, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(NawrisParcelOrder::class);
    }

    /**
     * The orders in this parcel.
     *
     * **This is what "rebuild the whole parcel" means.** An edit triggered by one order must send
     * the parcel's own figures, never that order's — with one order per parcel the two agree
     * today, and the day they stop agreeing is the day a silent bug would otherwise appear.
     *
     * @return BelongsToMany<Order, $this>
     */
    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(Order::class, 'nawris_parcel_orders')
            ->withPivot('amount_to_collect')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<ShippingCompany, $this>
     */
    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class);
    }

    /**
     * @return HasMany<NawrisWebhookEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(NawrisWebhookEvent::class);
    }

    /**
     * Still out there — dispatched, not yet finished.
     *
     * The one question the dispatch guard asks of an order's history, and it reads `closed_at`
     * rather than a status list precisely so a new terminal status cannot forget to update it.
     */
    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** A conflict was raised on this parcel and nobody has resolved it yet. */
    public function hasOpenConflict(): bool
    {
        return $this->conflict_raised_at !== null && $this->conflict_resolved_at === null;
    }
}
