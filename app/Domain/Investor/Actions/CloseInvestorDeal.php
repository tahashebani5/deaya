<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Investor\Enums\DealStatus;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Exceptions\DealIsNotEditable;
use App\Domain\Investor\Exceptions\DealStillHoldsStock;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Investor\Queries\DealOrdersInFlightQuery;
use App\Domain\Investor\Queries\InvestorBalances;
use App\Domain\Investor\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Ends a deal: settles each investor's result and hands his money back to his wallet.
 *
 * **This is the only door profit ever walks through to become withdrawable.** Nothing else
 * writes `profit_release`, so «الربح يأتي تدريجياً ولا يُسحب إلا عند انتهاء الصفقة» needs no
 * guard anywhere else — there is simply no other path.
 *
 * Two refusals, and the second is the one that is easy to miss:
 *
 * - **Stock left on the shelf.** Obvious: the deal is still trading.
 * - **An order that ate its stock has not been delivered yet.** Stock leaves at «جاهزة للطباعة»,
 *   days before delivery, so a deal's layers read empty long before its sales are final. Close it
 *   there and the capital goes back to the wallet and out as cash — and then the parcel comes
 *   home, is cancelled, and `CreditBackStockBatches` returns the goods to a closed deal's layers.
 *   The company would be holding the stock and the investor the money.
 *
 * The order of writes matters and is fixed: settle the loss against capital, release what is
 * left of the capital, then release the profit. A release before a writedown would hand back
 * money the deal had already lost.
 */
final class CloseInvestorDeal
{
    public function __construct(
        private readonly InvestorBalances $balances,
        private readonly DealOrdersInFlightQuery $ordersInFlight,
    ) {}

    public function __invoke(InvestorDeal $deal): InvestorDeal
    {
        if ($deal->status !== DealStatus::Open) {
            throw DealIsNotEditable::make((string) $deal->code);
        }

        return DB::transaction(function () use ($deal): InvestorDeal {
            $locked = InvestorDeal::query()->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->stillHoldsStock()) {
                throw DealStillHoldsStock::make((string) $locked->code);
            }

            $inFlight = ($this->ordersInFlight)((int) $locked->getKey());

            if ($inFlight !== []) {
                throw DealStillHoldsStock::make(
                    (string) $locked->code.' — طلبيات لم تُسلَّم بعد: '.implode('، ', $inFlight)
                );
            }

            foreach ($this->balances->forDeal((int) $locked->getKey())['per_investor'] as $investorId => $pots) {
                $this->settle($locked, (int) $investorId, $pots['capital'], $pots['profit']);
            }

            $locked->status = DealStatus::Closed;
            $locked->closed_at = now();
            $locked->save();

            return $locked;
        });
    }

    /** Settles one investor: the loss out of his capital here, then both pots back to the wallet. */
    private function settle(InvestorDeal $deal, int $investorId, string $capital, string $profit): void
    {
        // A loss comes out of the capital he put into THIS deal, and out of nothing else. Every
        // amount in the ledger is positive, so a negative profit has no release to hand back
        // with — this row is what turns −6,500 against 30,000 of capital into 23,500 returned.
        if (bccomp($profit, '0', 2) < 0) {
            $shortfall = substr($profit, 1);
            $fromCapital = bccomp($shortfall, $capital, 2) > 0 ? $capital : $shortfall;

            if (bccomp($fromCapital, '0', 2) > 0) {
                $this->write($deal, $investorId, WalletEntryType::CapitalWritedown, $fromCapital);
                $capital = bcsub($capital, $fromCapital, 2);
                $profit = bcadd($profit, $fromCapital, 2);
            }

            // Nothing in the arrangement makes him owe more than he put in, so what is left is
            // the company's — written as its own line so it shows on his statement instead of
            // disappearing into a difference nobody can name.
            if (bccomp($profit, '0', 2) < 0) {
                $absorbed = substr($profit, 1);
                $this->write($deal, $investorId, WalletEntryType::LossAbsorbedByCompany, $absorbed);
                $profit = '0.00';
            }
        }

        if (bccomp($profit, '0', 2) > 0) {
            $this->write($deal, $investorId, WalletEntryType::ProfitRelease, $profit);
        }

        if (bccomp($capital, '0', 2) > 0) {
            $this->write($deal, $investorId, WalletEntryType::Release, $capital);
        }
    }

    private function write(InvestorDeal $deal, int $investorId, WalletEntryType $type, string $amount): void
    {
        $entry = new InvestorWalletEntry([
            'amount' => Money::round($amount),
            'occurred_at' => now(),
        ]);

        $entry->investor_id = $investorId;
        $entry->investor_deal_id = $deal->getKey();
        $entry->type = $type;
        $entry->save();
    }
}
