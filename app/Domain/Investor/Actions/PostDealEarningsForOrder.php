<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Inventory\InventoryService;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Investor\Support\OrderDealSlices;
use App\Domain\Order\OrderService;
use Illuminate\Support\Facades\DB;

/**
 * Splits one delivered order's profit among the deals whose stock it sold, and writes each
 * investor's share into his ledger.
 *
 * **The number is the order's own.** `grand_total − total_cogs`, exactly as the order screen
 * already shows it — not the profit-and-loss report's different figure, which excludes the
 * delivery fee, the additional charge and the discount. Taking the order's own number is what
 * makes the discount and a post-deduction shortage fall out by construction: both move
 * `grand_total`, and whatever is left is what gets split.
 *
 * **Written once, at «تم الاستلام».** Before delivery an order can still come home and be
 * cancelled, and its profit with it. After delivery the state machine allows only «تم التسوية»,
 * `UpdateOrder` refuses every edit on a closed order, and `total_cogs` was frozen at «جاهزة» —
 * so the figure can never move again and a written row never needs restating. The practical
 * consequence, which is worth knowing rather than discovering: the «لماذا سُحب منّي» log will
 * almost never fire for an order. It fires for expenses, damage, and corrections.
 *
 * ## How one order's profit reaches one investor
 *
 * ```
 * deal slice    = OrderDealSlices — the order's money, split across the shelves it drew from
 * investors     = slice × investor_funded_percent ÷ 100 × investor_profit_share_percent ÷ 100
 * each investor = largest-remainder split of that over share_percent
 * ```
 *
 * The first line is {@see OrderDealSlices}, which is also what `GET /investor-deals/{deal}/orders`
 * reads to show a person the order behind his figure. The second is
 * {@see InvestorDeal::investorsCutOf()}, shared with the expense that is charged the same way:
 * the slice is what the deal's goods earned, all of it, and the partners own only the fraction of
 * those goods their money bought — the company is a partner for the rest. So the slice stays
 * whole on the deal's order screen, the investors' share shrinks, and the company's share is the
 * residual. The last line is this class's own, because it is the only part that concerns who
 * gets paid rather than what was earned.
 */
final class PostDealEarningsForOrder
{
    /** The statuses at which an order's figures can no longer move. */
    private const RECOGNISED = ['delivered', 'settled'];

    public function __construct(
        private readonly OrderService $orders,
        private readonly InventoryService $inventory,
        private readonly PostDealShare $postShare,
    ) {}

    /**
     * @return list<InvestorWalletEntry> the rows written, empty when there was nothing to post
     */
    public function __invoke(int $orderId): array
    {
        $order = $this->orders->profitAttributionFor($orderId);

        if ($order === null
            || ! in_array($order['status'], self::RECOGNISED, true)
            || $order['gross_profit'] === null
            || $order['lines'] === []) {
            return [];
        }

        $slices = $this->sliceByDeal($order);

        if ($slices === []) {
            return [];
        }

        return DB::transaction(function () use ($slices, $orderId): array {
            $written = [];

            // Ascending by id, always — the deadlock discipline this whole context shares with
            // CreditBackStockBatches.
            ksort($slices);

            foreach ($slices as $dealId => $slice) {
                $deal = InvestorDeal::query()->whereKey($dealId)->lockForUpdate()->first();

                if ($deal === null) {
                    continue;
                }

                foreach ($this->rowsFor($deal, $slice, $orderId) as $row) {
                    $written[] = $row;
                }
            }

            return $written;
        });
    }

    /**
     * Each deal's share of this order's profit, keyed by deal id.
     *
     * The arithmetic itself is {@see OrderDealSlices}, shared with the screen that shows a person
     * why he was paid this — one definition, so the two can never come to disagree.
     *
     * @param  array<string, mixed>  $order
     * @return array<int, string>
     */
    private function sliceByDeal(array $order): array
    {
        return OrderDealSlices::profitsOf(OrderDealSlices::forOrder(
            $order,
            $this->inventory->consumptionBreakdownFor(
                array_map(fn (array $line) => $line['movement_id'], $order['lines']),
            ),
        ));
    }

    /**
     * Turns one deal's slice into one row per investor.
     *
     * The slice is what the deal's goods earned, all of it; {@see InvestorDeal::investorsCutOf()}
     * takes the partners' fraction of it — the goods their money bought, and their half of what
     * that fraction made — and {@see PostDealShare} turns the result into ledger rows.
     *
     * @return list<InvestorWalletEntry>
     */
    private function rowsFor(InvestorDeal $deal, string $slice, int $orderId): array
    {
        return ($this->postShare)(
            $deal,
            $deal->investorsCutOf($slice),
            AuditSubject::Order->value,
            $orderId,
            'تصحيح إسناد ربح الطلبية',
        );
    }
}
