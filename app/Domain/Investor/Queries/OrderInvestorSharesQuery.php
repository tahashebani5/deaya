<?php

declare(strict_types=1);

namespace App\Domain\Investor\Queries;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Inventory\InventoryService;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Investor\Support\OrderDealSlices;
use App\Domain\Investor\Support\StockPurchaseMargins;
use App\Domain\Order\OrderService;
use Illuminate\Support\Facades\DB;

/**
 * What one order gave the investors behind its stock, and whether they have been paid it yet.
 *
 * **The mirror of {@see DealOrdersQuery}, asked from the other end.** That one answers «أي
 * طلبيات كانت مرتبطة بالصفقة»; this answers «هذه الطلبية — من أخذ منها، وكم، وهل أخذه فعلاً».
 * Same ledger, same arithmetic, opposite direction — and nothing new is stored for either.
 *
 * ## The two roads never share a column, and that is the point
 *
 * A deal reaches one order by one of two entirely different routes, and adding their figures
 * together would be a lie in whichever direction it was read:
 *
 *   * **`plain_sale`** — the press *bought* the deal's plain bags off the shelf at سعر السادة the
 *     moment they left it. The money is **above** the order's cost line, inside
 *     `order_items.material_cost`: it is part of what this order paid out, not a share of what it
 *     earned. Its `basis` is the margin, `32 × كمية − التكلفة`, and it is paid at «جاهزة
 *     للطباعة» — before the customer has seen anything.
 *   * **`order_profit`** — the old road, for a deal with no agreed price. The investors ride the
 *     sale itself and their share is carved **out of** the order's own profit, at «تم الاستلام».
 *
 * So a row says which road it is on, and the screen keeps them apart. Printed as one subtotal
 * under «مجمل الربح» they would suggest the profit divides into them, which is true of the second
 * and false of the first.
 *
 * ## Three sources of money, one order
 *
 * `plain_sale` is paid per **line** (`order_items.id`) for what the line took, and per
 * **movement** (`stock_movements.id`) for anything a spoiled run took afterwards — «هي من لما
 * تكون جاهزة وبينخصم من المخزون خلاص اعطيه حقاته». `order_profit` is paid per **order**. All
 * three are read here and folded into one row per deal per road, because a person reading this
 * screen is asking about a deal, not about a posting key.
 *
 * **Scrap movements are found by `reference_id`**, which is safe here and nowhere else:
 * `MovementType::ScrapLoss` rows have exactly one writer — `Order\Actions\RecordScrapLoss` —
 * and it always stamps the order. The warning on that column concerns `OrderFulfillment`, which
 * a generic endpoint can post with any reference at all.
 */
final class OrderInvestorSharesQuery
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function __invoke(int $orderId): array
    {
        $rows = array_merge(
            $this->plainSales($orderId),
            $this->orderProfits($orderId),
        );

        if ($rows === []) {
            return [];
        }

        $deals = InvestorDeal::query()
            ->whereKey(array_column($rows, 'deal_id'))
            ->get(['id', 'code'])
            ->keyBy('id');

        $sealed = [];

        foreach ($rows as $row) {
            $deal = $deals[$row['deal_id']] ?? null;

            if ($deal === null) {
                continue;
            }

            $sealed[] = [...$row, 'deal_code' => (string) $deal->code];
        }

        return $sealed;
    }

    /**
     * The deals the press bought plain stock from on this order — its lines, and any spoiled run
     * after them.
     *
     * @return list<array<string, mixed>>
     */
    private function plainSales(int $orderId): array
    {
        $lines = $this->orders->stockPurchaseAttributionFor($orderId);
        $scrap = $this->scrapMovements($orderId);

        $sources = [];

        foreach ($lines as $line) {
            $sources[] = [
                'movement_id' => $line['movement_id'],
                'source_type' => AuditSubject::OrderItem->value,
                'source_id' => $line['line_id'],
            ];
        }

        foreach ($scrap as $movementId) {
            $sources[] = [
                'movement_id' => $movementId,
                'source_type' => AuditSubject::StockMovement->value,
                'source_id' => $movementId,
            ];
        }

        if ($sources === []) {
            return [];
        }

        $breakdown = $this->inventory->consumptionBreakdownFor(
            array_column($sources, 'movement_id'),
        );

        /** @var array<int, array{margin: string, goods: string, paid: string, paid_at: ?string, posted: bool}> $byDeal */
        $byDeal = [];

        foreach ($sources as $source) {
            $draws = $breakdown[$source['movement_id']] ?? [];
            $margins = StockPurchaseMargins::byDeal($draws);
            $goods = StockPurchaseMargins::paidByDeal($draws);
            $posted = $this->postedFor($source['source_type'], [$source['source_id']]);

            foreach ($margins as $dealId => $margin) {
                $standing = $posted[$dealId] ?? null;

                $byDeal[$dealId] ??= [
                    'margin' => '0.00', 'goods' => '0.00',
                    'paid' => '0.00', 'paid_at' => null, 'posted' => false,
                ];

                $byDeal[$dealId]['margin'] = bcadd($byDeal[$dealId]['margin'], $margin, 2);
                $byDeal[$dealId]['goods'] = bcadd($byDeal[$dealId]['goods'], $goods[$dealId] ?? '0.00', 2);

                if ($standing !== null) {
                    $byDeal[$dealId]['posted'] = true;
                    $byDeal[$dealId]['paid'] = bcadd($byDeal[$dealId]['paid'], $standing['amount'], 2);
                    // The latest of them: a spoiled run is paid days after the line was.
                    $byDeal[$dealId]['paid_at'] = max($byDeal[$dealId]['paid_at'] ?? '', $standing['at']);
                }
            }
        }

        $rows = [];

        foreach ($byDeal as $dealId => $figures) {
            $deal = InvestorDeal::query()->whereKey($dealId)->first();

            if ($deal === null) {
                continue;
            }

            $share = $deal->investorsCutOf($figures['margin']);

            $rows[] = [
                'deal_id' => $dealId,
                'kind' => 'plain_sale',
                'goods_amount' => $figures['goods'],
                'profit' => $figures['margin'],
                'investors_share' => $share,
                'company_share' => bcsub($figures['margin'], $share, 2),
                'is_paid' => $figures['posted'],
                'paid_amount' => $figures['posted'] ? $figures['paid'] : null,
                'paid_at' => $figures['paid_at'],
            ];
        }

        return $rows;
    }

    /**
     * The deals still riding this order's own sale — a deal with no agreed price.
     *
     * @return list<array<string, mixed>>
     */
    private function orderProfits(int $orderId): array
    {
        $order = $this->orders->profitAttributionFor($orderId);

        if ($order === null || $order['lines'] === []) {
            return [];
        }

        $slices = OrderDealSlices::forOrder(
            $order,
            $this->inventory->consumptionBreakdownFor(
                array_map(fn (array $line) => $line['movement_id'], $order['lines']),
            ),
        );

        $posted = $this->postedFor(AuditSubject::Order->value, [$orderId]);
        $rows = [];

        foreach ($slices as $dealId => $slice) {
            $deal = InvestorDeal::query()->whereKey($dealId)->first();

            if ($deal === null) {
                continue;
            }

            $share = $deal->investorsCutOf($slice['profit']);
            $standing = $posted[$dealId] ?? null;

            $rows[] = [
                'deal_id' => $dealId,
                'kind' => 'order_profit',
                'goods_amount' => null,
                'profit' => $slice['profit'],
                'investors_share' => $share,
                'company_share' => bcsub($slice['profit'], $share, 2),
                // **Not «is the order delivered».** The ledger is the only thing that knows
                // whether money moved, and an order can be delivered with a share that rounded
                // to nothing — for which the posting action deliberately writes no row.
                'is_paid' => $standing !== null,
                'paid_amount' => $standing['amount'] ?? null,
                'paid_at' => $standing['at'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function scrapMovements(int $orderId): array
    {
        return DB::table('stock_movements')
            ->where('movement_type', 'scrap_loss')
            ->where('reference_id', $orderId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * What the ledger actually holds for these sources, per deal — net of reversals.
     *
     * A loss comes back signed, so a deal that lost on a lorry priced under its landed cost reads
     * as the negative it is rather than as a payment.
     *
     * @param  list<int>  $sourceIds
     * @return array<int, array{amount: string, at: string}>
     */
    private function postedFor(string $sourceType, array $sourceIds): array
    {
        $entries = InvestorWalletEntry::query()
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $sourceIds)
            ->whereIn('type', [WalletEntryType::Profit->value, WalletEntryType::Loss->value])
            ->whereDoesntHave('reversedBy')
            ->get(['investor_deal_id', 'type', 'amount', 'occurred_at']);

        $posted = [];

        foreach ($entries as $entry) {
            $dealId = (int) $entry->investor_deal_id;
            $signed = $entry->type === WalletEntryType::Loss
                ? '-'.$entry->amount
                : (string) $entry->amount;

            $posted[$dealId] ??= ['amount' => '0.00', 'at' => (string) $entry->occurred_at];
            $posted[$dealId]['amount'] = bcadd($posted[$dealId]['amount'], $signed, 2);
            $posted[$dealId]['at'] = max($posted[$dealId]['at'], (string) $entry->occurred_at);
        }

        return $posted;
    }
}
