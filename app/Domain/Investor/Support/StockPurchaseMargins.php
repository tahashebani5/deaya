<?php

declare(strict_types=1);

namespace App\Domain\Investor\Support;

use App\Domain\Investor\Actions\PostDealStockPurchases;
use App\Domain\Investor\Queries\OrderInvestorSharesQuery;

/**
 * What each deal made when the press bought its plain stock off the shelf, keyed by deal id.
 *
 * **Pure, and shared by the two callers that must never disagree** — the same arrangement
 * {@see OrderDealSlices} has for the other road. {@see PostDealStockPurchases} takes these
 * margins and pays the investors out of them; {@see OrderInvestorSharesQuery} takes the same
 * margins to show a person on the order screen where his money came from. Restated in two
 * places, the screen and the ledger drift the first day somebody fixes one of them.
 *
 * ```
 * per priced draw   margin = printing_sale_price × quantity − total_cost
 * deal margin       = Σ over that deal's priced draws
 * ```
 *
 * **Only the priced draws.** A layer with no `printing_sale_price` was never sold to the press —
 * the company's own stock, or a deal opened before the term — and its financier is still riding
 * the sale itself, to be paid at delivery by the other road. Both kinds sit under one movement,
 * and each is answered by the road it belongs to.
 *
 * The margin may legitimately be negative, on a lorry that landed dearer than the price agreed
 * for it; the ledger has a `loss` type for exactly that, and the sign travels with the figure.
 */
final class StockPurchaseMargins
{
    /**
     * @param  list<array<string, mixed>>  $draws  every draw of one movement
     * @return array<int, string> keyed by deal id, zero margins dropped
     */
    public static function byDeal(array $draws): array
    {
        $margins = [];

        foreach ($draws as $draw) {
            if ($draw['printing_sale_price'] === null || $draw['investor_deal_id'] === null) {
                continue;
            }

            $dealId = (int) $draw['investor_deal_id'];

            // Rounded on the draw, exactly as `Order\Support\MaterialCost` rounds what the line
            // was charged for it — the two must be the same number, or the press pays one figure
            // and the deal is credited with another.
            $paid = Money::round(bcmul($draw['printing_sale_price'], $draw['quantity'], 8));

            $margins[$dealId] = bcadd(
                $margins[$dealId] ?? '0.00',
                bcsub($paid, $draw['total_cost'], 2),
                2,
            );
        }

        return array_filter($margins, fn (string $margin) => bccomp($margin, '0', 2) !== 0);
    }

    /**
     * What the press paid for one movement's priced draws, keyed by deal id — the gross figure
     * the margin above is the profit on.
     *
     * Read only by the screen: the ledger needs the margin, a person reading «كم أخذ المستثمر من
     * هذه الطلبية» needs to see the price beside it.
     *
     * @param  list<array<string, mixed>>  $draws
     * @return array<int, string>
     */
    public static function paidByDeal(array $draws): array
    {
        $paid = [];

        foreach ($draws as $draw) {
            if ($draw['printing_sale_price'] === null || $draw['investor_deal_id'] === null) {
                continue;
            }

            $dealId = (int) $draw['investor_deal_id'];

            $paid[$dealId] = bcadd(
                $paid[$dealId] ?? '0.00',
                Money::round(bcmul($draw['printing_sale_price'], $draw['quantity'], 8)),
                2,
            );
        }

        return $paid;
    }
}
