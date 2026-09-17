<?php

declare(strict_types=1);

namespace App\Domain\Investor\Support;

use App\Domain\Investor\Queries\DealOrdersQuery;
use App\Domain\Order\Queries\ProfitAttributionQuery;

/**
 * One order's money, split across the deals whose goods it sold.
 *
 * **Pure, and shared by the two callers that must never disagree.** {@see
 * \App\Domain\Investor\Actions\PostDealEarningsForOrder} takes the profit out of this to pay the
 * investors; {@see DealOrdersQuery} takes the whole row out of it to
 * show a person why he was paid that. Restated in two places, the screen and the ledger drift the
 * first day somebody fixes one of them — so the arithmetic lives here and neither owns it.
 *
 * Nothing here touches the database. Both callers hand it the order's frozen figures and the
 * draws behind them, which is what makes the split reproducible: `line_total`, the conversion
 * costs and every `stock_batch_consumptions.total_cost` are written once and never move again, so
 * recomputing this a year later returns the figure that was actually paid.
 *
 * ```
 * E(O)          = grand_total − items_total        the order-level extras, net of the discount
 * line_revenue  = line_total + share of E(O)       allocated across lines by line_total
 * per draw c    revenue    = share of line_revenue    ← by quantity, over EVERY draw of the
 *               conversion = share of the line's        movement, the company's included
 *                            labour + overhead + outsourcing
 *               material   = c.total_cost exactly     ← never allocated; it is already exact
 *               profit(c)  = revenue − conversion − material
 * deal slice    = Σ over that deal's draws
 * ```
 *
 * The denominator is the whole movement. A line that drew 1,000 units from a deal and 2,000 from
 * the company's own stock gives the deal a third of the line's money — allocating across the
 * deal's rows alone would hand it all of it, because a proportional split distributes its total
 * across whatever weights it is given.
 */
final class OrderDealSlices
{
    /**
     * What each deal put into this order and took out of it, keyed by deal id.
     *
     * The company's own draws are walked — they carry weight in every allocation — and then
     * dropped: they belong to nobody this class is answering about.
     *
     * @param  array{grand_total: string, items_total: string, lines: list<array{
     *     movement_id: int, stock_purchased: bool, line_total: string, conversion_cost: string
     * }>}  $order  as {@see ProfitAttributionQuery} returns it
     * @param  array<int, list<array{
     *     investor_deal_id: ?int, printing_sale_price: ?string, quantity: string, total_cost: string
     * }>>  $breakdown  every draw of every line's movement, keyed by movement id
     * @return array<int, array{
     *     quantity: string,
     *     material_cost: string,
     *     revenue: string,
     *     conversion_cost: string,
     *     profit: string
     * }>
     */
    public static function forOrder(array $order, array $breakdown): array
    {
        $extras = bcsub($order['grand_total'], $order['items_total'], 2);

        $extraShares = Money::allocate(
            $extras,
            // Weights must not be negative, and a line total never is.
            array_map(fn (array $line): string => $line['line_total'], $order['lines']),
        );

        $slices = [];

        foreach ($order['lines'] as $index => $line) {
            $draws = $breakdown[$line['movement_id']] ?? [];

            if ($draws === []) {
                continue;
            }

            $lineRevenue = bcadd($line['line_total'], $extraShares[$index] ?? '0.00', 2);
            $weights = array_map(fn (array $draw): string => $draw['quantity'], $draws);

            $revenue = Money::allocate($lineRevenue, $weights);
            $conversion = Money::allocate($line['conversion_cost'], $weights);

            foreach ($draws as $position => $draw) {
                $dealId = $draw['investor_deal_id'];

                if ($dealId === null) {
                    continue;
                }

                // **A draw the press already paid for belongs to nobody here.** The line bought
                // it off the deal at سعر السادة the day it left the shelf, and that margin is in
                // the investor's ledger; letting the delivery split it again would pay him twice
                // for one kilo. Dropped *after* its weight has done its work above — the
                // allocation's denominator is still every draw of the movement — so what its
                // share of the revenue would have been simply stays with the company, which is
                // precisely who owns those goods now.
                if ($line['stock_purchased'] && $draw['printing_sale_price'] !== null) {
                    continue;
                }

                $slices[$dealId] ??= self::empty();

                $slices[$dealId] = self::add($slices[$dealId], [
                    'quantity' => $draw['quantity'],
                    'material_cost' => $draw['total_cost'],
                    'revenue' => $revenue[$position] ?? '0.00',
                    'conversion_cost' => $conversion[$position] ?? '0.00',
                ]);
            }
        }

        return self::sealed($slices);
    }

    /**
     * The profits alone, zero slices dropped — what paying the investors needs and nothing else.
     *
     * A loop rather than `array_map`, and it matters: `array_map` renumbers integer keys, so
     * mapping over a map keyed by deal id relabels deal 7 as deal 0 and pays the wrong people.
     *
     * @param  array<int, array{profit: string}>  $slices
     * @return array<int, string>
     */
    public static function profitsOf(array $slices): array
    {
        $profits = [];

        foreach ($slices as $dealId => $slice) {
            if (bccomp($slice['profit'], '0', 2) !== 0) {
                $profits[$dealId] = $slice['profit'];
            }
        }

        return $profits;
    }

    /**
     * @return array{quantity: string, material_cost: string, revenue: string, conversion_cost: string}
     */
    private static function empty(): array
    {
        return [
            'quantity' => '0',
            'material_cost' => '0.00',
            'revenue' => '0.00',
            'conversion_cost' => '0.00',
        ];
    }

    /**
     * @param  array<string, string>  $carry
     * @param  array<string, string>  $draw
     * @return array<string, string>
     */
    private static function add(array $carry, array $draw): array
    {
        return [
            'quantity' => bcadd($carry['quantity'], $draw['quantity'], 3),
            'material_cost' => bcadd($carry['material_cost'], $draw['material_cost'], 2),
            'revenue' => bcadd($carry['revenue'], $draw['revenue'], 2),
            'conversion_cost' => bcadd($carry['conversion_cost'], $draw['conversion_cost'], 2),
        ];
    }

    /**
     * Closes each slice with the figure the other four exist to produce.
     *
     * @param  array<int, array<string, string>>  $slices
     * @return array<int, array{
     *     quantity: string, material_cost: string, revenue: string,
     *     conversion_cost: string, profit: string
     * }>
     */
    private static function sealed(array $slices): array
    {
        $sealed = [];

        foreach ($slices as $dealId => $slice) {
            $sealed[$dealId] = [
                'quantity' => number_format((float) $slice['quantity'], 3, '.', ''),
                'material_cost' => $slice['material_cost'],
                'revenue' => $slice['revenue'],
                'conversion_cost' => $slice['conversion_cost'],
                'profit' => bcsub(
                    bcsub($slice['revenue'], $slice['conversion_cost'], 2),
                    $slice['material_cost'],
                    2,
                ),
            ];
        }

        return $sealed;
    }
}
