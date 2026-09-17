<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Application\Api\V1\Requests\Order\ChangeOrderStatusRequest;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\TransitionFields;

/**
 * Writes what the foreman measured onto the lines, on the way into «جاهزة».
 *
 * **The only writer of `warehouse_quantity` there is.** It used to be typed when the order was
 * taken, which asked a clerk on the phone for a weight nobody had been near a scale to read —
 * the parcel did not exist yet. Now the person who has it in front of them answers, and this is
 * where their answer lands, in the same transaction as the status change and immediately before
 * {@see DeductOrderStock} reads it back through {@see OrderItem::producedQuantity()}.
 *
 * **A line the move did not ask about is left exactly as it is.** Only lines stocked in a unit
 * other than the one they were sold in are asked — see {@see TransitionFields} — so an absent
 * key means "this line needs no measurement", never "clear what is there". Treating absence as
 * a clear would wipe the figure off every line on every move.
 *
 * The values arrive already validated as numbers by {@see ChangeOrderStatusRequest},
 * which builds its rules from the same field list that asked for them.
 */
final class SetOrderStockQuantities
{
    /**
     * @param  array<string, mixed>  $fields  What the move was given — see {@see TransitionFields}.
     */
    public function __invoke(Order $order, array $fields): void
    {
        foreach ($order->items as $item) {
            $value = $fields[TransitionFields::stockQuantityKey($item)] ?? null;

            // An empty string is the app sending an untouched optional box, which is a silence
            // and not a measurement of zero.
            if ($value === null || $value === '') {
                continue;
            }

            $item->forceFill(['warehouse_quantity' => (string) $value])->save();
        }
    }
}
