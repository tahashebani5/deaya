<?php

declare(strict_types=1);

namespace App\Domain\Order\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order's lines have taken their material off the shelf, and each one's draw is now final for
 * this move.
 *
 * Dispatched on the way into «جاهزة للطباعة» and «جاهزة» — after the deduction itself, and after
 * a restatement has corrected what the press actually used, so `fulfillment_stock_movement_id`
 * on every line already points at the movement that is going to stand.
 *
 * **This is the moment the press buys its plain material**, and therefore the moment an investor
 * who sells to it at سعر السادة is paid — «كأننا بنشروه من المستثمر». Nothing about the sale that
 * follows reaches him: not the customer's price, not the wages of the run, not a cancellation.
 *
 * **An event rather than a call**, for the reason {@see OrderProfitFinalised} sets out at length:
 * Orders may not depend on Investment, Investment already depends on Orders, and a direct call
 * would close a loop the container cannot build. It fires on every such move, including the many
 * where no line drew a funded layer at all — whoever listens is expected to find nothing and
 * write nothing, which is the ordinary case.
 */
final readonly class OrderStockDrawn
{
    use Dispatchable;

    public function __construct(public int $orderId) {}
}
