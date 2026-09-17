<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\Concerns\FiltersOrders;

/**
 * How many orders answer one question — the same question, asked the same way.
 *
 * **It exists so a number on a card and the screen that card opens cannot disagree.** The home
 * board's «بانتظار رسالة الجاهزية» is this count, and tapping it runs {@see OrderListQuery} with
 * the identical {@see OrderFilters}; both reach the predicate through {@see FiltersOrders}, so
 * there is one definition of the queue and no way for a second to appear.
 *
 * Deliberately not a fifth method on the counts queries beside it: those group *by* something —
 * a status, a payment state — and answer with a row apiece. This answers with one number for one
 * filter, which is a different shape and a different question.
 */
final class OrderCountQuery
{
    use FiltersOrders;

    public function __invoke(OrderFilters $filters): int
    {
        return $this->applyFilters(Order::query(), $filters)->count();
    }
}
