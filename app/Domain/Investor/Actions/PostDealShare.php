<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Investor\Support\Money;
use Illuminate\Support\Collection;

/**
 * Writes one figure, already reduced to the investors' share, into their ledgers — and makes sure
 * it is the only figure standing for that source.
 *
 * **Extracted so two roads can share one hand on the ledger.** A deal earns in two entirely
 * different ways now — a delivered order's profit ({@see PostDealEarningsForOrder}) and the
 * margin the press pays when it buys plain stock off the shelf
 * ({@see PostDealStockPurchases}) — and each computes its own amount from its own facts. What
 * neither may own privately is *how a figure becomes rows*: the largest-remainder split across
 * shares, the idempotent comparison against what already stands, and the correction written as a
 * reversal plus a fresh sequence. Two copies of that is two behaviours the day somebody fixes one.
 *
 * **Idempotent by comparison rather than by hope.** The same source posted twice computes the
 * same figures and writes nothing the second time. A source whose figure has *changed* — a
 * restated line, an order re-delivered after an edit — reverses every standing row and writes
 * the new ones under the next sequence, so the ledger keeps both the wrong answer and its
 * correction, which is the rule every money table in this application follows.
 *
 * **And zero is a figure like any other.** A source whose amount has fallen to nothing — a
 * restated line that no longer draws on this deal, a correction that cancels the margin exactly —
 * reverses every standing row and writes none. Returning early on a zero would leave the first
 * payment standing for a source that owes nothing, which is the one shape of this bug that pays a
 * man for goods he still has on the shelf.
 *
 * The caller holds the deal's row lock; the partial unique index behind
 * `(investor, deal, source_type, source_id, source_sequence)` is the database's own backstop for
 * the day a future one forgets.
 */
final class PostDealShare
{
    /**
     * @param  string  $investorsAmount  the investors' share, signed — negative is a loss
     * @param  string  $sourceType  an `AuditSubject` value: what produced this figure
     * @param  int  $sourceId  the id of that thing
     * @return list<InvestorWalletEntry>
     */
    public function __invoke(
        InvestorDeal $deal,
        string $investorsAmount,
        string $sourceType,
        int $sourceId,
        string $correctionNote,
    ): array {
        $shares = $deal->shares()->get();

        if ($shares->isEmpty()) {
            return [];
        }

        $amounts = Money::allocate(
            $investorsAmount,
            $shares->map(fn ($share) => (string) $share->share_percent)->all(),
        );

        $standing = InvestorWalletEntry::query()
            ->where('investor_deal_id', $deal->getKey())
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('type', [WalletEntryType::Profit->value, WalletEntryType::Loss->value])
            ->whereDoesntHave('reversedBy')
            ->get()
            ->keyBy('investor_id');

        $target = [];

        foreach ($shares as $index => $share) {
            $target[(int) $share->investor_id] = $amounts[$index] ?? '0.00';
        }

        if ($this->matches($standing, $target)) {
            return [];
        }

        $sequence = 1 + (int) InvestorWalletEntry::query()
            ->where('investor_deal_id', $deal->getKey())
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->max('source_sequence');

        foreach ($standing as $entry) {
            $this->reverse($entry, $correctionNote);
        }

        $written = [];

        foreach ($target as $investorId => $amount) {
            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }

            $isLoss = bccomp($amount, '0', 2) < 0;

            $entry = new InvestorWalletEntry([
                'amount' => $isLoss ? substr($amount, 1) : $amount,
                'occurred_at' => now(),
            ]);

            $entry->investor_id = $investorId;
            $entry->investor_deal_id = $deal->getKey();
            $entry->type = $isLoss ? WalletEntryType::Loss : WalletEntryType::Profit;
            $entry->source_type = $sourceType;
            $entry->source_id = $sourceId;
            $entry->source_sequence = $sequence;
            $entry->save();

            $written[] = $entry;
        }

        return $written;
    }

    /**
     * @param  Collection<int, InvestorWalletEntry>  $standing
     * @param  array<int, string>  $target
     */
    private function matches($standing, array $target): bool
    {
        $current = [];

        foreach ($standing as $entry) {
            $signed = $entry->type === WalletEntryType::Loss
                ? '-'.$entry->amount
                : (string) $entry->amount;

            $current[(int) $entry->investor_id] = $signed;
        }

        $wanted = array_filter($target, fn (string $amount) => bccomp($amount, '0', 2) !== 0);

        if (array_keys($current) !== array_keys($wanted)) {
            return false;
        }

        foreach ($wanted as $investorId => $amount) {
            if (bccomp($current[$investorId], $amount, 2) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function reverse(InvestorWalletEntry $entry, string $note): void
    {
        $reversal = new InvestorWalletEntry([
            'amount' => (string) $entry->amount,
            'occurred_at' => now(),
            'notes' => $note,
        ]);

        $reversal->investor_id = $entry->investor_id;
        $reversal->investor_deal_id = $entry->investor_deal_id;
        $reversal->type = WalletEntryType::Reversal;
        $reversal->reverses_entry_id = $entry->getKey();
        $reversal->save();
    }
}
