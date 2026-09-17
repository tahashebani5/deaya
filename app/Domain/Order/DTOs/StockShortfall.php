<?php

declare(strict_types=1);

namespace App\Domain\Order\DTOs;

use App\Domain\Order\Actions\DeductOrderStock;
use App\Domain\Order\Exceptions\OrderStockShortfall;

/**
 * One shelf an order asks a warehouse for more of than it holds.
 *
 * Carries the name as well as the two numbers, because that is the whole point of it: a refusal
 * that says only «(0.000) لا تكفي لـ (50.000)» sends whoever pressed the button back to the
 * order to work out what it meant. See {@see OrderStockShortfall}.
 *
 * **One entry per stock item, not per order line.** Two lines drawing on the same pile are one
 * shortage of one thing, and their requirements are added together before either is compared —
 * see {@see DeductOrderStock}.
 */
final readonly class StockShortfall
{
    public function __construct(
        /**
         * The shelf, as the warehouse knows it — «كيس شحن 25*35».
         *
         * Deliberately not the line's «المنتج — المقاس» snapshot: two different products can be
         * the reason one pile ran out, so naming either of them sends the storekeeper looking for
         * the wrong thing.
         */
        public string $name,

        /** What the shelf holds, as a string for the same reason a balance is one. */
        public string $available,

        /** Everything this order asks of that shelf, all its lines added together. */
        public string $required,
    ) {}
}
