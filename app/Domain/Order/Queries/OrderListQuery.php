<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Queries\Concerns\FiltersOrders;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The orders list.
 *
 * Newest first by default: an order screen is a work queue, and the thing taken five minutes ago
 * is the one somebody is asking about. {@see OrderSort} turns it round for the other question a
 * queue is opened with — «ما الذي ينتظر منذ أطول وقت؟» — which the default cannot answer at all
 * past the first page.
 *
 * **And the archive is this same query, not another one.** Which of the two lists is being asked
 * for rides on {@see OrderFilters::$archived} and is applied by {@see FiltersOrders}, so the
 * archive inherits every filter, every sort and every eager load here for free — and can never
 * drift from the live list in what a status or a search means.
 */
final class OrderListQuery
{
    use FiltersOrders;

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function __invoke(OrderFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = Order::query()
            // Eager-loaded: the list renders the customer's name and counts the lines, and
            // strict mode turns a forgotten load into an exception rather than a query per row.
            //
            // The whole row, not a column list: CustomerResource renders `is_active`, and under
            // strict mode an attribute that was never selected throws instead of reading null.
            // A narrower select here is a 500 the next time that resource gains a field.
            ->with('customer')
            // The lines, for the same reason the customer is: every row is asked what its moves
            // would want, and two of those answers are made of lines — «نواقص» asks per size
            // («الناقص من 30*30»), and whether «جاهزة» must have a weight depends on whether
            // any line is sold by the kilo. Left to fetch themselves that is a query per order,
            // which is what a work queue can least afford.
            ->with('items')
            // And the product behind each line, with its photographs: a card in the list shows
            // what is in the order — the picture, the code, the name — and only the primary
            // image is ever serialized, so this is one URL per line and not a gallery per row.
            ->with('items.product.images')
            // And the shelf behind each line, because the card states what the order weighs and
            // the sum is over the *stock* unit — a run sold by the piece off a pile counted by
            // the kilo weighs something, one off a shelf counted in pieces does not. See
            // Order::totalWeight(). Without it that method's own `loadMissing()` fetches the
            // pair one order at a time, which is the query per row this list is built to avoid.
            ->with('items.variant.stockItem')
            // **And what is printed on the order.** The card draws the artwork rather than the
            // catalogue's photograph — every line of every order shows the same white bag
            // otherwise — so the versions and the files behind them travel with the page. Two
            // queries for the whole page, not two per row: `customerDesign` is what holds the
            // path, and `CustomerDesignResource` signs the link from it without touching the
            // disk. Rejected versions come too; which of them is the one being printed is a
            // reading rule, and the client that draws them owns it.
            ->with('designs.customerDesign')
            ->withCount('items');

        $direction = $filters->sort->direction();

        return $this->applyFilters($query, $filters)
            // **`placed_at`, not `id`, and the same column the date filter counts on** — see
            // FiltersOrders. They are the same instant for every order this API takes and part
            // company the day an old one is imported, and «الأقدم» means the day it was taken.
            ->orderBy('placed_at', $direction)
            // The tiebreaker, and not decoration: two orders taken in the same second leave
            // Postgres free to hand back either first, and a row that changes places between
            // page one and page two is a row read twice and a row never seen.
            ->orderBy('id', $direction)
            ->paginate($perPage);
    }
}
