<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Investor\DTOs\DealFunderData;
use App\Domain\Investor\DTOs\DealItemData;
use App\Domain\Investor\DTOs\DealShareData;
use App\Domain\Investor\DTOs\FundPurchaseOrderData;
use App\Domain\Investor\DTOs\InvestorDealData;
use App\Domain\Investor\DTOs\WalletEntryData;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Exceptions\DealTakesNoMoreCapital;
use App\Domain\Investor\Exceptions\PurchaseOrderCannotBeFunded;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealSupply;
use App\Domain\Investor\Support\Money;
use App\Domain\PurchaseOrder\Queries\FundingSnapshotQuery;
use Illuminate\Support\Facades\DB;

/**
 * Turns a purchase order into a funded deal in one act: the shelves, the claim and the money.
 *
 * **This is the deal being born from the document it was always about.** The old path asked a
 * person to type the materials into a deal form, then find the same order again and claim each
 * of its lines by hand — the shelves written twice and linked a third time, for information the
 * purchase order already held. Here the lines *are* the shelves, the claim is every one of them,
 * and what is left to type is a name and a column of amounts.
 *
 * The order it runs in is the order the guards need:
 *
 * 1. **The order may still be funded** — nothing received yet, and the lines this deal is to
 *    fund are lines of it that nobody has claimed. Read before anything is written, so a
 *    refusal costs nothing.
 * 2. **The deal, as a draft**, with the order's distinct shelves on it. `SyncDealItems` puts
 *    each one through Catalog's investability door, so an order carrying one shelf the business
 *    does not invest in refuses the whole thing rather than funding part of a lorry.
 * 3. **The company's stake and the partners' fraction**, worked out from the cost of those lines
 *    and the column of amounts — see below.
 * 4. **The money**, one `allocation` per funder through {@see RecordWalletEntry}, which reads
 *    each wallet's ceiling from the locked row. A man who has not got it stops the whole
 *    transaction, and no half-funded deal is left behind. Written while the deal is still a
 *    draft, because once it opens it takes no more capital ({@see DealTakesNoMoreCapital}).
 * 5. **Opened**, which freezes the terms — they are what the money will be split by.
 * 6. **Every line claimed**, so `ReceivePurchaseOrder` finds its answer per line at arrival and
 *    the storekeeper is never asked. After the opening, because a claim on a draft is refused.
 *
 * **One order may carry several deals — one per group of lines.** The claim has always been
 * per line (`investor_deal_supplies` is keyed by `(source, line)`), so a lorry bringing three
 * shelves may be financed by three different sets of partners. What is refused is two deals over
 * the *same* line, because `dealForSupply` answers with the first row it finds and the second
 * would be ignored in silence.
 *
 * **The percentages are not typed.** They are the amounts, split by largest remainder to sum to
 * exactly 100 — «أحمد ٣٠٬٠٠٠ ومحمد ٢٠٬٠٠٠» *is* 60/40. A typed percentage beside a typed amount
 * is two numbers that can disagree about the same partnership.
 *
 * **The company is a partner for what the money did not cover.** The owner's rule: «الشركة لما
 * تحط فلوس تكون كأنها طرف تاني — الـ50% تنقسم بيناتنا». So beside the partners' percentages two
 * more figures are derived here and frozen with them: `company_stake`, the landed cost of the
 * chosen lines less what was put in — «الباقي على الشركة» exactly as the screen prints it — and
 * `investor_funded_percent`, the fraction of those lines the partners' money bought. Every
 * profit, loss and expense on the deal is multiplied by that fraction before the investors' half
 * is taken ({@see InvestorDeal::investorsCutOf()}): three men with 3,000 in a 20,000 lorry own
 * 15% of it, and are paid half of what that 15% earns. Measured against the chosen lines, never
 * the whole order, because a second deal may stand on the order's other lines.
 */
final class FundPurchaseOrder
{
    /**
     * The least a man may come into a deal with.
     *
     * **A floor on the stake, not on the money.** A hundred dinars buys a share that rounds to
     * noise in every split it touches, and it buys a row in the ledger, a line on the deal
     * screen and a partner to answer to at closing — the accounting costs more than the stake.
     * Topping up a deal he is already in is a different act and has no floor.
     */
    private const MINIMUM_STAKE = '1000.00';

    public function __construct(
        private readonly FundingSnapshotQuery $snapshot,
        private readonly CreateInvestorDeal $createDeal,
        private readonly OpenInvestorDeal $openDeal,
        private readonly ClaimDealSupply $claimSupply,
        private readonly RecordWalletEntry $recordEntry,
    ) {}

    /**
     * @throws PurchaseOrderCannotBeFunded
     */
    public function __invoke(int $purchaseOrderId, FundPurchaseOrderData $data, ?int $actorId): InvestorDeal
    {
        return DB::transaction(function () use ($purchaseOrderId, $data, $actorId): InvestorDeal {
            $order = ($this->snapshot)($purchaseOrderId, lock: true);

            $this->guardOrder($order);
            $shelves = $this->shelvesToFund($order, $purchaseOrderId, $data->stockItemIds);
            $this->guardFunders($data->funders);
            $cost = $this->costOf($order, $shelves);
            $funded = $this->sumOf($data->funders);
            $this->guardAgainstOverfunding($funded, $cost);

            $deal = ($this->createDeal)(
                new InvestorDealData(
                    openedOn: now()->toDateString(),
                    items: array_map(
                        fn (int $stockItemId) => new DealItemData(stockItemId: $stockItemId),
                        $shelves,
                    ),
                    shares: $this->sharesFrom($data->funders),
                    investorProfitSharePercent: $data->investorProfitSharePercent,
                    notes: $data->notes,
                ),
                $actorId,
            );

            $deal->purchase_order_id = $purchaseOrderId;
            // Frozen here with the percentages, and for the same reason: it is the term the men
            // putting money in were shown. Every layer this deal's goods arrive on takes a copy.
            $deal->printing_sale_price = $data->printingSalePrice;
            $deal->company_stake = bcsub($cost, $funded, 2);
            $deal->investor_funded_percent = Money::allocatePercent([$funded, $deal->company_stake])[0];
            $deal->save();

            foreach ($data->funders as $funder) {
                ($this->recordEntry)(
                    new WalletEntryData(
                        investorId: $funder->investorId,
                        type: WalletEntryType::Allocation,
                        amount: $funder->amount,
                        investorDealId: (int) $deal->getKey(),
                        notes: $funder->notes,
                    ),
                    $actorId,
                );
            }

            ($this->openDeal)($deal);

            foreach ($shelves as $stockItemId) {
                ($this->claimSupply)($deal, $purchaseOrderId, $stockItemId, $actorId);
            }

            return $deal->load(['items.stockItem', 'shares.investor']);
        });
    }

    /**
     * @param  array{id: int, status: string, is_fundable: bool, stock_item_ids: list<int>, total_cost: string}|null  $order
     *
     * @throws PurchaseOrderCannotBeFunded
     */
    private function guardOrder(?array $order): void
    {
        if ($order === null) {
            throw PurchaseOrderCannotBeFunded::notFound();
        }

        if ($order['stock_item_ids'] === []) {
            throw PurchaseOrderCannotBeFunded::hasNoLines();
        }

        if (! $order['is_fundable']) {
            throw PurchaseOrderCannotBeFunded::alreadyArriving();
        }
    }

    /**
     * Which of the order's lines this deal funds — and the proof that they are free.
     *
     * Omitted, it is **every line nobody has claimed yet**, which is the whole order the first
     * time and the remainder afterwards. Named, each one must be a line of this order and must
     * still be unclaimed: a line already carrying a deal would otherwise be silently ignored at
     * receipt, because `dealForSupply` answers with the first row it finds.
     *
     * @param  array{id: int, status: string, is_fundable: bool, stock_item_ids: list<int>, total_cost: string}  $order
     * @param  list<int>|null  $requested
     * @return list<int>
     *
     * @throws PurchaseOrderCannotBeFunded
     */
    private function shelvesToFund(array $order, int $purchaseOrderId, ?array $requested): array
    {
        $claimed = InvestorDealSupply::query()
            ->where('source_type', AuditSubject::PurchaseOrder->value)
            ->where('source_id', $purchaseOrderId)
            ->pluck('investor_deal_id', 'stock_item_id')
            ->all();

        if ($requested === null) {
            $free = array_values(array_filter(
                $order['stock_item_ids'],
                fn (int $stockItemId) => ! array_key_exists($stockItemId, $claimed),
            ));

            if ($free === []) {
                throw PurchaseOrderCannotBeFunded::everyLineIsFunded();
            }

            return $free;
        }

        $shelves = array_values(array_unique(array_map('intval', $requested)));

        if ($shelves === []) {
            throw PurchaseOrderCannotBeFunded::noLinesChosen();
        }

        foreach ($shelves as $stockItemId) {
            if (! in_array($stockItemId, $order['stock_item_ids'], true)) {
                throw PurchaseOrderCannotBeFunded::lineIsNotOnTheOrder();
            }

            if (array_key_exists($stockItemId, $claimed)) {
                $deal = InvestorDeal::query()->whereKey($claimed[$stockItemId])->first();

                throw PurchaseOrderCannotBeFunded::lineAlreadyFunded((string) ($deal?->code ?? ''));
            }
        }

        return $shelves;
    }

    /**
     * @param  list<DealFunderData>  $funders
     *
     * @throws PurchaseOrderCannotBeFunded
     */
    private function guardFunders(array $funders): void
    {
        if ($funders === []) {
            throw PurchaseOrderCannotBeFunded::nothingWasPutIn();
        }

        $seen = [];

        foreach ($funders as $funder) {
            if (! Money::isPositive($funder->amount)) {
                throw PurchaseOrderCannotBeFunded::nothingWasPutIn();
            }

            if (bccomp($funder->amount, self::MINIMUM_STAKE, 2) < 0) {
                throw PurchaseOrderCannotBeFunded::stakeIsTooSmall(self::MINIMUM_STAKE);
            }

            // One row per man. Two rows for the same investor would be two percentages of one
            // partnership, and `SyncDealShares` finds his row by investor id — the second would
            // overwrite the first and the split would sum to 100 while paying him for one of them.
            if (in_array($funder->investorId, $seen, true)) {
                throw PurchaseOrderCannotBeFunded::listedTwice();
            }

            $seen[] = $funder->investorId;
        }
    }

    /**
     * The landed cost of the lines this deal is taking — the denominator of everything.
     *
     * **The chosen lines, not the lorry.** A second deal may stand on the order's other lines,
     * and measuring against the whole order would put the other deal's goods on the company's
     * side of this one's arithmetic.
     *
     * @param  array{line_costs: array<int, string>, ...}  $order
     * @param  list<int>  $shelves
     */
    private function costOf(array $order, array $shelves): string
    {
        $cost = '0.00';

        foreach ($shelves as $stockItemId) {
            $cost = bcadd($cost, $order['line_costs'][$stockItemId] ?? '0.00', 2);
        }

        return $cost;
    }

    /**
     * @param  list<DealFunderData>  $funders
     */
    private function sumOf(array $funders): string
    {
        $funded = '0.00';

        foreach ($funders as $funder) {
            $funded = bcadd($funded, $funder->amount, 2);
        }

        return $funded;
    }

    /**
     * Nobody puts in more than the goods cost.
     *
     * **The ceiling is the landed cost of the lines this deal is taking**, not the lorry's. Money
     * beyond it buys nothing: there is no more stock for it to own, and it would sit in the deal
     * earning a share of a shipment it did not pay for — and it would put the company's stake
     * below zero, which the schema refuses anyway.
     *
     * A line nobody has priced has a cost of zero here, so it refuses too. That is the same rule
     * `ReceivePurchaseOrder` already enforces at the gate — deal goods do not arrive without a
     * unit cost — said one step earlier, where it can still be fixed by typing the price.
     *
     * @throws PurchaseOrderCannotBeFunded
     */
    private function guardAgainstOverfunding(string $funded, string $cost): void
    {
        if (! Money::isPositive($cost)) {
            throw PurchaseOrderCannotBeFunded::linesHaveNoCost();
        }

        if (bccomp($funded, $cost, 2) > 0) {
            throw PurchaseOrderCannotBeFunded::moreThanTheGoodsCost($funded, $cost);
        }
    }

    /**
     * The amounts, as percentages that sum to exactly 100.
     *
     * `committed_amount` keeps the figure itself beside the percentage it produced, so the deal
     * screen can show «تعهّد بـ ٣٠٬٠٠٠» against what actually landed in the ledger — the two are
     * written in the same breath here, and may still drift apart later if capital is returned.
     *
     * @param  list<DealFunderData>  $funders
     * @return list<DealShareData>
     */
    private function sharesFrom(array $funders): array
    {
        $percents = Money::allocatePercent(
            array_map(fn (DealFunderData $funder) => $funder->amount, $funders),
        );

        $shares = [];

        foreach ($funders as $index => $funder) {
            $shares[] = new DealShareData(
                investorId: $funder->investorId,
                sharePercent: $percents[$index] ?? '0.0000',
                committedAmount: $funder->amount,
                notes: $funder->notes,
            );
        }

        return $shares;
    }
}
