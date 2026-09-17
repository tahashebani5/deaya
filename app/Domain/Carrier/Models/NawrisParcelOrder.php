<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Order\Models\Order;
use Database\Factories\NawrisParcelOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One order's place in one parcel.
 *
 * A model rather than a bare pivot, because it soft-deletes and keeps a history like everything
 * else here — and because a pivot with no model has no audit trail, which is the trap RULES.md
 * §10 names. Re-dispatching an order writes a second row rather than editing the first, so the
 * question «في أي طرد كانت هذه الطلبية؟» stays answerable for every parcel it was ever in.
 */
#[UseFactory(NawrisParcelOrderFactory::class)]
#[Fillable([])]
class NawrisParcelOrder extends Model
{
    /** @use HasFactory<NawrisParcelOrderFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_to_collect' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<NawrisParcel, $this>
     */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(NawrisParcel::class, 'nawris_parcel_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
