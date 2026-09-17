<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderStatusTransition;

/**
 * Writes one row of an order's timeline.
 *
 * Its own action because two callers need it and neither should own it: creating an order
 * records the move into `new` with no `from`, and every status change afterwards records a pair.
 * A history written in two places is a history with two shapes.
 */
final class RecordStatusTransition
{
    public function __invoke(
        Order $order,
        ?OrderStatus $from,
        OrderStatus $to,
        ?string $reason = null,
        ?User $actor = null,
    ): OrderStatusTransition {
        /** @var OrderStatusTransition $transition */
        $transition = $order->transitions()->create([
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'user_id' => $actor?->getKey(),
        ]);

        return $transition;
    }
}
