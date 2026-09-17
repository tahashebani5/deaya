<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Inventory\InventoryService;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Investor\Support\StockPurchaseMargins;
use App\Domain\Order\OrderService;
use Illuminate\Support\Facades\DB;

/**
 * Pays the deals whose plain stock the press has just bought off the shelf.
 *
 * **The second road a deal can earn on, and the whole of سعر السادة.** The owner's framing, on
 * 2026-09-06: «الشركة نفسها مطبعة — يعني كأننا بنشروه من المستثمر… أي حاجة تطلع من المخزون
 * الكيلو يمشي بسعر السادة بالوزن، كأنه باعها بيع ليّا… استلم الزبون ما استلمش، المطبعة تتحمّل».
 * The investor is not a partner in the printing job; he is a merchant of plain bags whose sale
 * completes at the warehouse door.
 *
 * ```
 * per priced draw   margin = printing_sale_price × quantity − total_cost
 * deal margin       = Σ over that deal's priced draws on this order's printed lines
 * investors' share  = margin × investor_funded_percent ÷ 100 × investor_profit_share_percent ÷ 100
 * each investor     = largest-remainder split of that over share_percent
 * ```
 *
 * **The same {@see InvestorDeal::investorsCutOf()} {@see PostDealEarningsForOrder} pays with**,
 * so what separates the two roads is *when* a deal is paid and *what* the figure is computed on —
 * never how it is divided. It was ownership alone until 2026-09-11, on the reading that a
 * purchase at an agreed price pays for no work; the owner settled it the other way that day —
 * «نعم على اغلب حتى هو بيتوزع 5/5» — and the press keeps the printing margin whole, which is the
 * separation he was after: «ولا يشارك مع ربح المطبعة».
 *
 * **Keyed on the order line, not on the draw.** A restatement — the press correcting what the run
 * actually used — replaces the line's movement and keeps the line, so keying here on
 * `order_items.id` lets {@see PostDealShare} recognise the corrected figure as the same source,
 * reverse what it wrote before and post the new one. Keyed on the movement, the first payment
 * would stand beside the second forever.
 *
 * **A payment is reversed by what was posted, never by what is now computed.** The corrected
 * figure decides the new amount; the *set of deals and lines to correct* is read from the ledger
 * itself, because a restatement can drop a deal — or a whole line — out of the draw entirely, and
 * a deal nobody recomputes is a deal nobody reverses.
 *
 * **And a cancellation is deliberately not undone.** Nothing calls this on the way to «ملغاة»;
 * the entries stay, and `ReverseOrderStockDeduction` hands the goods back to the company rather
 * than to the deal. That is «المطبعة تتحمّل» in the ledger.
 */
final class PostDealStockPurchases
{
    /** The note a corrected line's reversal carries in the ledger. */
    private const LINE_NOTE = 'تصحيح بيع السادة للمطبعة';

    public function __construct(
        private readonly OrderService $orders,
        private readonly InventoryService $inventory,
        private readonly PostDealShare $postShare,
    ) {}

    /**
     * @return list<InvestorWalletEntry> the rows written, empty when nothing was bought
     */
    public function __invoke(int $orderId): array
    {
        $lines = $this->orders->stockPurchaseAttributionFor($orderId);

        $breakdown = $lines === [] ? [] : $this->inventory->consumptionBreakdownFor(
            array_map(fn (array $line) => $line['movement_id'], $lines),
        );

        $written = [];

        foreach ($lines as $line) {
            foreach ($this->rowsForLine($line, $breakdown) as $row) {
                $written[] = $row;
            }
        }

        // **The lines that used to be here.** A restatement recomputes the line's cost off a
        // fresh draw, and a corrected run that no longer reaches a priced layer clears
        // `stock_purchased_at` — so the line leaves {@see StockPurchaseAttributionQuery}
        // altogether and the loop above never sees it again. Its first payment would stand
        // forever, against goods the credit-back has already put back on the investor's own
        // shelf. Posted with no draws at all, which reverses everything standing and writes
        // nothing.
        foreach ($this->strandedLines($orderId, array_column($lines, 'line_id')) as $lineId) {
            foreach ($this->post([], AuditSubject::OrderItem->value, $lineId, self::LINE_NOTE) as $row) {
                $written[] = $row;
            }
        }

        return $written;
    }

    /**
     * The order's lines that still hold money for a purchase they are no longer credited with.
     *
     * @param  list<int>  $attributed  the lines the attribution query still returns
     * @return list<int>
     */
    private function strandedLines(int $orderId, array $attributed): array
    {
        $candidates = array_values(array_diff($this->orders->lineIdsFor($orderId), $attributed));

        if ($candidates === []) {
            return [];
        }

        return InvestorWalletEntry::query()
            ->where('source_type', AuditSubject::OrderItem->value)
            ->whereIn('source_id', $candidates)
            ->whereIn('type', [WalletEntryType::Profit->value, WalletEntryType::Loss->value])
            ->whereDoesntHave('reversedBy')
            ->distinct()
            ->pluck('source_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * One further draw against an order that was already fulfilled — bags spoiled at the press.
     *
     * **Keyed on the movement, not on the line**, and that is the whole reason it is a second
     * entry point rather than a re-run of `__invoke()`. The line's own draw has not changed; this
     * is an extra one beside it, so posting it under the line id would make {@see PostDealShare}
     * read the first payment as a figure to correct and replace it with the second. A spoiled
     * run is its own event and is paid for on its own row.
     *
     * @return list<InvestorWalletEntry>
     */
    public function forMovement(int $stockMovementId): array
    {
        return $this->post(
            $this->inventory->consumptionBreakdownFor([$stockMovementId])[$stockMovementId] ?? [],
            AuditSubject::StockMovement->value,
            $stockMovementId,
            'تصحيح بيع سادة الهالك للمطبعة',
        );
    }

    /**
     * One line's purchase, deal by deal.
     *
     * A transaction per line rather than one around the whole order: each line is its own source
     * in the ledger and its own idempotent unit, and a second line failing must not unwrite the
     * first line's payment. The caller — `ChangeOrderStatus` — already holds a transaction around
     * the entire status move, so in practice this nests and commits with it; standing alone it
     * still cannot leave one line half paid.
     *
     * @param  array{line_id: int, movement_id: int}  $line
     * @param  array<int, list<array<string, mixed>>>  $breakdown
     * @return list<InvestorWalletEntry>
     */
    private function rowsForLine(array $line, array $breakdown): array
    {
        return $this->post(
            $breakdown[$line['movement_id']] ?? [],
            AuditSubject::OrderItem->value,
            $line['line_id'],
            self::LINE_NOTE,
        );
    }

    /**
     * Turns one movement's priced draws into wallet rows, under one source.
     *
     * @param  list<array<string, mixed>>  $draws
     * @return list<InvestorWalletEntry>
     */
    private function post(array $draws, string $sourceType, int $sourceId, string $correctionNote): array
    {
        $margins = StockPurchaseMargins::byDeal($draws);

        // **Every deal this source has ever paid, not only the ones it still owes.** A corrected
        // draw can stop reaching a deal's layer entirely — a smaller run that FIFO satisfies out
        // of an older layer, or a margin that recomputes to exactly zero, which
        // {@see StockPurchaseMargins::byDeal()} drops. Iterating the new margins alone would
        // never visit that deal again, and {@see PostDealShare} scopes its reversal by deal, so
        // its first payment would stand against stock that is back on its own shelf. A deal that
        // has fallen out is posted at zero, which reverses it.
        $dealIds = array_unique(array_merge(
            array_keys($margins),
            $this->dealsStandingOn($sourceType, $sourceId),
        ));

        if ($dealIds === []) {
            return [];
        }

        // Ascending by id, always — the deadlock discipline this whole context shares with
        // CreditBackStockBatches.
        sort($dealIds);

        return DB::transaction(function () use ($dealIds, $margins, $sourceType, $sourceId, $correctionNote): array {
            $written = [];

            foreach ($dealIds as $dealId) {
                $deal = InvestorDeal::query()->whereKey($dealId)->lockForUpdate()->first();

                if ($deal === null) {
                    continue;
                }

                $rows = ($this->postShare)(
                    $deal,
                    $deal->investorsCutOf($margins[$dealId] ?? '0.00'),
                    $sourceType,
                    $sourceId,
                    $correctionNote,
                );

                foreach ($rows as $row) {
                    $written[] = $row;
                }
            }

            return $written;
        });
    }

    /**
     * The deals holding un-reversed money for this source, whatever the draws now say.
     *
     * @return list<int>
     */
    private function dealsStandingOn(string $sourceType, int $sourceId): array
    {
        return InvestorWalletEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('type', [WalletEntryType::Profit->value, WalletEntryType::Loss->value])
            ->whereNotNull('investor_deal_id')
            ->whereDoesntHave('reversedBy')
            ->distinct()
            ->pluck('investor_deal_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
