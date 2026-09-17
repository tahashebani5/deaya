<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Enums\DesignSource;
use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\Order\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * [Total Revenue (product + service)] − [Total COGS (material + manufacturing)] = Gross Profit,
 * over a date range — plus cash actually collected in the same window, reported alongside rather
 * than netted against COGS.
 *
 * **Reads across `Order` directly rather than through `OrderService`** — a deliberate, documented
 * exception to RULES.md §3. This context's entire purpose is cross-cutting read aggregation over
 * figures three other contexts already compute and cache; routing each number through a service
 * method would not reduce coupling, only relocate it. What this query must **not** do is
 * re-derive any of those figures itself: every SUM below is over an already-cached column
 * (`items_total`, `design_fee`, `total_cogs`, `order_items.material_cost_actual`/`labor_cost`/
 * `overhead_cost`, `order_payments.amount`), never a re-computation of a money rule that already
 * has one home.
 *
 * **Materials are costed at `material_cost_actual`.** A printed line that bought its plain bags
 * off an investor at سعر السادة carries two material figures — what it paid, and what the goods
 * cost the business — and a company-wide statement is only ever the second. See the note beside
 * the breakdown below. The one exception — which orders' `design_fee` actually counts as revenue — is
 * the same `design_source = 'in_house'` condition {@see RecalculateOrderTotals}
 * already states once; restating it here in SQL is the same trade-off
 * `Order\Support\PaymentStatusExpression` already makes for exactly this reason: a report reading
 * across every order cannot afford to load each one into PHP just to ask one boolean question of it.
 *
 * **Revenue is recognised on delivery or settlement, accrual-style — `total_cogs` has no cash-basis
 * counterpart.** Materials were already paid for separately, through purchase orders; gross profit
 * is therefore only ever computed against the accrual figures. `cash_collected` is reported
 * beside it as a reconciliation figure, at the caller's chosen basis (`order_payments.paid_at`),
 * never subtracted from anything — pairing it against COGS would imply a cash-basis margin this
 * model does not have.
 *
 * **`additional_cost` is deliberately absent from revenue here, as `delivery_price` and
 * `discount` already are.** All three sit inside `grand_total` and none of them is a product or
 * a service this statement recognises: they are charges and reliefs on the way to what the
 * customer pays. Folding the packaging charge into `revenue.service` would make this report
 * disagree with its own delivery line, which is the inconsistency the omission avoids. A
 * business that later wants «كم حصّلنا مقابل التغليف؟» answered has the column and its reason
 * code waiting — that is a new figure on this statement, not a redefinition of an existing one.
 *
 * **The `losses` section is reported, never subtracted** — see the query that builds it. Both
 * kinds of loss are already inside gross profit by construction, so the section names money the
 * shop could otherwise see only as a margin that came out thin. It is the one place `ScrapLoss`
 * has ever appeared on this statement.
 *
 * `design_fee` carries no cost of its own anywhere in this codebase — see BUSINESS-FIELDS-DESIGN
 * and the plan's own note — so service revenue is, by construction, 100% margin here. Not a bug:
 * a deliberate, already-approved simplification.
 */
final class ProfitAndLossSummaryQuery
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(ProfitAndLossFilters $filters): array
    {
        $recognized = Order::query()
            ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Settled->value])
            ->whereBetween(DB::raw('COALESCE(delivered_at, settled_at)'), [$filters->from, $filters->to]);

        $productRevenue = (string) (clone $recognized)->sum('items_total');

        $serviceRevenue = (string) (clone $recognized)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN design_source = ? THEN design_fee ELSE 0 END), 0) as total',
                [DesignSource::InHouse->value],
            )
            ->value('total');

        $cogs = (string) (clone $recognized)->sum('total_cogs');

        $orderIds = (clone $recognized)->pluck('id');

        // **`material_cost_actual`, not `material_cost` — and this statement is the reason that
        // column exists.** Since سعر السادة, a printed line's `material_cost` is what the *press*
        // paid the deal that financed its plain bags, which is the right basis for that line's
        // own profit and the wrong one for the company's. The difference between the two never
        // left the business: it moved from the press's pocket to the deal's, and the investors'
        // slice of it is a distribution out of the wallet, not a cost of goods. Summing the
        // charged figure here would understate company-wide gross profit by the whole internal
        // margin — the company's own share of it included.
        //
        // `total_cogs` above is the same charged figure rolled up per order, so the margin is
        // netted out of it below rather than the column being redefined: an order's own margin
        // must keep costing it what it paid.
        $costBreakdown = OrderItem::query()
            ->whereIn('order_id', $orderIds)
            ->selectRaw(
                'COALESCE(SUM(COALESCE(material_cost_actual, material_cost)), 0) as material, '.
                'COALESCE(SUM(labor_cost), 0) as labor, '.
                'COALESCE(SUM(overhead_cost), 0) as overhead, '.
                'COALESCE(SUM(material_cost - COALESCE(material_cost_actual, material_cost)), 0) as transfer_margin',
            )
            ->first();

        $cogs = bcsub($cogs, (string) $costBreakdown->transfer_margin, 8);

        $cashCollected = (string) OrderPayment::query()
            ->where('type', OrderPaymentType::Payment->value)
            ->whereBetween('paid_at', [$filters->from, $filters->to])
            ->sum('amount');

        // **Money the business decided it will never collect** — the difference on an order that
        // came back short, closed on the record rather than typed in as a payment nobody
        // received. See OrderPaymentType::WriteOff.
        //
        // Reported beside `cash_collected` and deliberately *not* subtracted from gross profit:
        // this statement recognises revenue when the order is delivered, and carries no expense
        // side at all — there is no opex line here to put a bad debt on. Netting it off the
        // gross would quietly mix an accrual figure with a collection one and leave neither
        // readable. It is the same reconciliation shelf `cash_collected` already sits on.
        //
        // A write-off that was undone is not a loss, so the reversed ones are left out. Note the
        // difference from `cash_collected` above, which counts every `payment` row in the period
        // whether or not it was later cancelled — that figure answers «كم دخل الدرج» and is left
        // exactly as it was rather than quietly redefined here.
        $writeOffs = (string) OrderPayment::query()
            ->where('type', OrderPaymentType::WriteOff->value)
            ->whereBetween('paid_at', [$filters->from, $filters->to])
            ->whereDoesntHave('reversal')
            ->sum('amount');

        // **Goods this business made and never sold** — spoiled on the press, or made, counted
        // and left on the counter by the customer who ordered them.
        //
        // **Reported beside the statement and deliberately not subtracted from it**, the same
        // shelf `cash_collected` and `write_offs` already sit on. Both figures are *already* in
        // gross profit by construction and subtracting them here would charge the same goods
        // twice:
        //
        // - Scrap draws fresh stock off a shelf and never reaches a line's `cogs`; what it costs
        //   the business shows up as inventory that left without revenue beside it.
        // - A partial delivery lowers revenue through `billableQuantity()` while the cost frozen
        //   at «جاهزة» stays whole, so the whole of it has already come out of the margin.
        //
        // What this section adds is a *name* for money the shop can otherwise see only as a
        // margin that came out thin — and the two are named apart because they are fixed by
        // different people. A press problem and a counter problem are not one number.
        //
        // **Scrap is here for the first time**, and it predates partial delivery by a year:
        // `RecordScrapLoss` has been writing entries that no statement ever read, because the
        // report sums the cached item columns and
        // `RecalculateOrderItemManufacturingCost` folds a loss into neither of them. Shipping
        // «خسارة التسليم الجزئي» alone under a heading called «الخسائر» would have made that
        // silence look like an answer.
        //
        // One query with a CASE rather than two: both rows read the same table with the same
        // active-entry rule and differ only in `cost_type`.
        //
        // Active entries only — neither a reversal itself nor one that has been undone — which
        // is the rule `RecalculateOrderItemManufacturingCost::activeEntriesFor()` already states
        // and `write_offs` above already follows for its own reversals. A loss that was reversed
        // because the goods turned up is not a loss.
        $losses = ProductionCostEntry::query()
            ->whereIn('order_id', $orderIds)
            ->whereIn('cost_type', [
                ManufacturingCostType::ScrapLoss->value,
                ManufacturingCostType::DeliveryLoss->value,
            ])
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversal')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN cost_type = ? THEN amount ELSE 0 END), 0) as scrap, '.
                'COALESCE(SUM(CASE WHEN cost_type = ? THEN amount ELSE 0 END), 0) as partial_delivery',
                [
                    ManufacturingCostType::ScrapLoss->value,
                    ManufacturingCostType::DeliveryLoss->value,
                ],
            )
            ->first();

        $revenueTotal = Money::sum($productRevenue, $serviceRevenue);

        return [
            'period' => [
                'from' => $filters->from->toDateString(),
                'to' => $filters->to->toDateString(),
            ],
            'revenue' => [
                'product' => Money::round($productRevenue),
                'service' => Money::round($serviceRevenue),
                'total' => $revenueTotal,
            ],
            'cost_of_goods_sold' => [
                'material' => Money::round((string) $costBreakdown->material),
                'labor' => Money::round((string) $costBreakdown->labor),
                'overhead' => Money::round((string) $costBreakdown->overhead),
                'total' => Money::round($cogs),
            ],
            'gross_profit' => Money::round(bcsub($revenueTotal, $cogs, 8)),
            'cash_collected' => Money::round($cashCollected),
            'write_offs' => Money::round($writeOffs),

            // Named, never netted — see the query above. `total` is here so a reader who wants
            // one figure is not left adding two on a phone.
            'losses' => [
                'scrap' => Money::round((string) $losses->scrap),
                'partial_delivery' => Money::round((string) $losses->partial_delivery),
                'total' => Money::sum(
                    (string) $losses->scrap,
                    (string) $losses->partial_delivery,
                ),
            ],
            'orders_recognized' => $orderIds->count(),
        ];
    }
}
