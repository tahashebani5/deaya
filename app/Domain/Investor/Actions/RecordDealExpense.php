<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Investor\DTOs\DealExpenseData;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealExpense;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Investor\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Books a cost against a deal, and charges the investors their share of it.
 *
 * The expense row is the record — «حتى يمكن معرفة مصدر كل تكلفة» — and the `loss` rows it writes
 * are what actually move anybody's money. Keeping both means the deal's profit is one walk of one
 * ledger whatever produced the numbers: a sale, a spoiled pallet, or a customs invoice that
 * turned up late.
 *
 * `is_landed` is false for everything a person records. Shipping and customs typed on a purchase
 * order are already inside the cost of the layers that arrived — proportioned into
 * `final_unit_cost` and snapshotted into every consumption row — so a mirrored row is kept for
 * the record and never charged again. Subtracting it twice is the single most likely way to pay
 * an investor for one invoice twice.
 */
final class RecordDealExpense
{
    public function __invoke(InvestorDeal $deal, DealExpenseData $data, ?int $actorId): InvestorDealExpense
    {
        return DB::transaction(function () use ($deal, $data, $actorId): InvestorDealExpense {
            $locked = InvestorDeal::query()->whereKey($deal->getKey())->lockForUpdate()->firstOrFail();

            $expense = new InvestorDealExpense([
                'kind' => $data->kind,
                'name' => $data->name,
                'amount' => $data->amount,
                'incurred_on' => $data->incurredOn,
                'notes' => $data->notes,
            ]);

            $expense->investor_deal_id = $locked->getKey();
            $expense->is_landed = false;
            $expense->recorded_by = $actorId;
            $expense->save();

            $this->chargeInvestors($locked, $expense);

            return $expense;
        });
    }

    /**
     * The investors' cut of the cost — the same two factors a profit goes through, then the same
     * split among them.
     *
     * {@see InvestorDeal::investorsCutOf()} rather than a multiplication of its own, and that is
     * the whole point: on a deal whose partners bought 15% of the goods, a 1,000 customs invoice
     * costs them 75, exactly as a 1,000 profit would pay them 75. Charging the whole invoice by
     * their half alone took 500 from men who own 750 of the profit.
     *
     * A cost the company bears alone would be a different arrangement, and nothing in it says so.
     */
    private function chargeInvestors(InvestorDeal $deal, InvestorDealExpense $expense): void
    {
        $shares = $deal->shares()->get();

        if ($shares->isEmpty()) {
            return;
        }

        $investorsAmount = $deal->investorsCutOf((string) $expense->amount);

        if (bccomp($investorsAmount, '0', 2) <= 0) {
            return;
        }

        $amounts = Money::allocate(
            $investorsAmount,
            $shares->map(fn ($share) => (string) $share->share_percent)->all(),
        );

        foreach ($shares as $index => $share) {
            $amount = $amounts[$index] ?? '0.00';

            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            $entry = new InvestorWalletEntry([
                'amount' => $amount,
                'occurred_at' => now(),
            ]);

            $entry->investor_id = $share->investor_id;
            $entry->investor_deal_id = $deal->getKey();
            $entry->type = WalletEntryType::Loss;
            $entry->source_type = AuditSubject::InvestorDealExpense->value;
            $entry->source_id = $expense->getKey();
            $entry->save();
        }
    }
}
