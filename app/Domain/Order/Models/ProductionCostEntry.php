<?php

declare(strict_types=1);

namespace App\Domain\Order\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\ManufacturingCostType;
use Database\Factories\ProductionCostEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One entry in an order's production-cost ledger — labour, machine runtime, overhead or scrap.
 *
 * **Nothing updates one and nothing deletes one**, the same rule {@see OrderPayment} follows and
 * for the same reason: a correction is a further row pointing back at the one it undoes
 * (`reverses_entry_id`), not a rewrite of what was recorded.
 *
 * `quantity`/`rate` are set together or not at all — a rate-driven entry snapshots both so the
 * amount stays explicable after the rate table changes; a scrap-loss entry leaves both null,
 * since its `amount` comes from FIFO batch cost, not a rate.
 */
#[UseFactory(ProductionCostEntryFactory::class)]
#[Fillable(['quantity', 'rate', 'notes'])]
class ProductionCostEntry extends Model
{
    /** @use HasFactory<ProductionCostEntryFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_type' => ManufacturingCostType::class,
            'quantity' => 'decimal:3',
            'rate' => 'decimal:3',
            // A string, not a float: summed into order_items.labor_cost/overhead_cost, and money
            // that is summed must stay exact.
            'amount' => 'decimal:2',
            'incurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * The entry this one undoes. Set on a reversal and null on everything else.
     *
     * @return BelongsTo<ProductionCostEntry, $this>
     */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * The reversal that undid this entry, if one exists.
     *
     * @return HasOne<ProductionCostEntry, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Whether this entry has been undone.
     */
    public function isReversed(): bool
    {
        if ($this->relationLoaded('reversal')) {
            return $this->reversal !== null;
        }

        return $this->reversal()->exists();
    }
}
