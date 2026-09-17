<?php

declare(strict_types=1);

namespace App\Domain\Order\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use Database\Factories\OrderStatusTransitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One move an order made.
 *
 * Written once and never edited. It still soft deletes and is audited because every model in
 * `app/Domain/` does — a history with an exception in it is a history nobody can trust — but
 * nothing in the application updates one.
 *
 * `from_status` is null exactly once per order: the row recording that it was taken.
 */
#[UseFactory(OrderStatusTransitionFactory::class)]
#[Fillable(['from_status', 'to_status', 'reason', 'user_id'])]
class OrderStatusTransition extends Model
{
    /** @use HasFactory<OrderStatusTransitionFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
