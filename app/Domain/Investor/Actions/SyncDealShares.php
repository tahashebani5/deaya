<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Investor\DTOs\DealShareData;
use App\Domain\Investor\Exceptions\DealIsNotEditable;
use App\Domain\Investor\Exceptions\DealSharesMustSumToOneHundred;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorWalletEntry;

/**
 * Replaces a deal's participants with the set that was sent.
 *
 * **The percentages must sum to exactly 100.** Guarded here rather than by a CHECK because a
 * row-level constraint cannot see its siblings, and taken under the deal's lock — held by the
 * caller — because two concurrent syncs each pass on their own and the pair that survives sums
 * to something else, at which point the profit split quietly distributes more or less than the
 * pool while every individual row still looks right.
 *
 * **A share with money against it is not removable.** The foreign key says `restrictOnDelete`
 * and that is dead code under soft deletes, so the guard has to be here: removing the row would
 * orphan every wallet entry pointing at it, and the money would vanish from the deal screen
 * while remaining in the investor's ledger.
 *
 * Removals go one at a time so each leaves an audit entry — the `SyncOrderItems` rule: a mass
 * `->delete()` on a relation fires no model events and leaves a hole exactly where somebody will
 * look for «من أخرج أحمد من الصفقة؟».
 */
final class SyncDealShares
{
    /**
     * @param  list<DealShareData>  $shares
     *
     * @throws DealIsNotEditable
     * @throws DealSharesMustSumToOneHundred
     */
    public function __invoke(InvestorDeal $deal, array $shares): InvestorDeal
    {
        if (! $deal->isEditable()) {
            throw DealIsNotEditable::make((string) $deal->code);
        }

        $this->guardSum($shares);

        $keep = array_map(fn (DealShareData $share) => $share->investorId, $shares);

        foreach ($deal->shares()->get() as $existing) {
            if (in_array((int) $existing->investor_id, $keep, true)) {
                continue;
            }

            $this->guardNoMoney($deal, (int) $existing->investor_id);
            $existing->delete();
        }

        foreach ($shares as $share) {
            // As in SyncDealItems, and for the same reason: `investor_id` is not fillable, so
            // the row is found by it and assigned it, never filled with it.
            $row = $deal->shares()->where('investor_id', $share->investorId)->first()
                ?? $deal->shares()->make();

            $row->fill([
                'committed_amount' => $share->committedAmount,
                'share_percent' => $share->sharePercent,
                'notes' => $share->notes,
            ]);

            $row->investor_id = $share->investorId;
            $row->joined_at ??= now();
            $row->save();
        }

        return $deal->load('shares.investor');
    }

    /**
     * @param  list<DealShareData>  $shares
     */
    private function guardSum(array $shares): void
    {
        $sum = '0';

        foreach ($shares as $share) {
            $sum = bcadd($sum, $share->sharePercent, 4);
        }

        if (bccomp($sum, '100.0000', 4) !== 0) {
            throw DealSharesMustSumToOneHundred::make($sum);
        }
    }

    private function guardNoMoney(InvestorDeal $deal, int $investorId): void
    {
        $hasMoney = InvestorWalletEntry::query()
            ->where('investor_deal_id', $deal->getKey())
            ->where('investor_id', $investorId)
            ->exists();

        if ($hasMoney) {
            throw DealIsNotEditable::make((string) $deal->code);
        }
    }
}
