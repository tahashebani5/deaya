<?php

declare(strict_types=1);

namespace App\Domain\Investor\Queries;

use Illuminate\Support\Facades\DB;

/**
 * The orders that ate this deal's stock and have not become final yet.
 *
 * **The reason closing a deal is not simply «is the shelf empty».** Stock leaves at «جاهزة
 * للطباعة», days before a parcel reaches anybody, and the road from there runs through «راجع
 * مندوب» and «راجع مكتب» to a full cancellation — at which point `CreditBackStockBatches` puts
 * the goods back into the very layers this deal owns. A deal closed and paid out in between
 * leaves the company holding the stock and the investor holding the money.
 *
 * Only `delivered` and `settled` are safe: from those the state machine offers no road back.
 *
 * **And a draw already bought at سعر السادة waits on one thing only — the restatement.** Its
 * money is settled and a cancellation hands its goods to the company, so delivery is no longer
 * this deal's business; but the press correcting the weight at «جاهزة» credits the draw back to
 * the deal's own layers. `ready_at` is what says that door has shut.
 *
 * Written as a query builder rather than through OrderService because it reads nothing but ids
 * and a status string, and returning order *codes* for a refusal message is the whole of it.
 */
final class DealOrdersInFlightQuery
{
    /**
     * @return list<string> the order codes blocking the close, empty when nothing does
     */
    public function __invoke(int $dealId): array
    {
        return DB::table('stock_batch_consumptions as c')
            ->join('stock_batches as b', 'b.id', '=', 'c.stock_batch_id')
            ->join('stock_movements as m', 'm.id', '=', 'c.stock_movement_id')
            ->join('order_items as oi', 'oi.fulfillment_stock_movement_id', '=', 'm.id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('b.investor_deal_id', $dealId)
            ->whereNull('b.deleted_at')
            ->whereNull('c.deleted_at')
            ->whereNull('m.deleted_at')
            ->whereNull('oi.deleted_at')
            ->whereNull('o.deleted_at')
            ->whereNotIn('o.status', ['delivered', 'settled', 'cancelled'])
            // **A draw the press has already bought is not waiting on anything — once the press
            // can no longer change its mind about it.** It was paid for at سعر السادة the day it
            // left the shelf, and a *cancellation* now returns the goods to the company rather
            // than to this deal, so the parcel's fate cannot reach the layers here.
            //
            // **But a restatement can, and that is why `ready_at` is read.** The press correcting
            // what the run actually used credits the whole draw back to the deal's *own* layers —
            // `RestateOrderStockDeduction` deliberately does not hand them over, because it is
            // undoing the draw rather than writing off a sale. That correction happens on the
            // move to «جاهزة» and nowhere else, so an order with no `ready_at` still holds a
            // road back onto this shelf. Closed in that window, the deal releases capital and
            // profit for withdrawal and then has stock reappear on it.
            //
            // Reinstating a cancelled order does not re-open the window: `ready_at` is never
            // cleared, and «جاهزة» is reached once.
            ->where(fn ($q) => $q
                ->whereNull('b.printing_sale_price')
                ->orWhereNull('oi.stock_purchased_at')
                ->orWhereNull('o.ready_at'))
            // A reversed draw is not holding anything: the goods went back to the shelf.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('stock_movements as r')
                ->whereColumn('r.reverses_movement_id', 'm.id')
                ->whereNull('r.deleted_at'))
            ->distinct()
            ->orderBy('o.id')
            ->pluck('o.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }
}
