<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorWalletEntry;
use Illuminate\Support\Facades\DB;

/**
 * Takes an archived order's earnings back out of the wallets it paid into.
 *
 * **The mirror of {@see PostDealEarningsForOrder}, and it cannot simply be that action run
 * again.** That one derives its figure from `OrderService::profitAttributionFor()`, a
 * soft-delete-scoped read: on a deleted order it answers `null` and the action returns *before*
 * reaching {@see PostDealShare}, so every standing row keeps standing. The order drops out of
 * the profit-and-loss report — `ProfitAndLossSummaryQuery` reads `Order::query()` — while three
 * investors go on holding a share of a sale nobody counts. See §٢٫١ of
 * Docs/orders/ORDER-DELETE-AND-ARCHIVE.md.
 *
 * **Nothing new is computed here: the target is zero, and `PostDealShare` already knows what to
 * do with zero.** Its own docblock says so — «وzero is a figure like any other»: a source whose
 * amount has fallen to nothing reverses every standing row and writes none. So this class
 * contributes exactly one fact, «هذه الطلبية لم تعد تُنتج شيئاً», and the largest-remainder
 * split, the idempotent comparison and the reversal-not-edit rule stay in the single place that
 * owns them. Writing the reversals here by hand was the alternative and it duplicates the one
 * body that must never come to have two behaviours.
 *
 * **Idempotent for free.** Run twice, the second pass finds no standing rows and writes nothing —
 * which matters because a delete may be attempted after a delete, and because the restore
 * deliberately does *not* re-post: §٢٫١ says reversing a reversal is a third financial event
 * nobody asked for, and a restored order that is genuinely delivered again earns again the
 * ordinary way, through {@see PostDealEarningsForOrder}.
 *
 * Deals are locked ascending by id — the deadlock discipline this whole context shares.
 */
final class UnwindDealEarningsForOrder
{
    /** What a reversal written by this path says in the investor's statement. */
    private const REASON = 'عكس ربح طلبية محذوفة';

    public function __construct(private readonly PostDealShare $postShare) {}

    /**
     * @return list<InvestorWalletEntry> always empty — the rows this writes are reversals, and
     *                                   {@see PostDealShare} returns only what it posts
     */
    public function __invoke(int $orderId): array
    {
        $dealIds = InvestorWalletEntry::query()
            ->where('source_type', AuditSubject::Order->value)
            ->where('source_id', $orderId)
            ->whereIn('type', [WalletEntryType::Profit->value, WalletEntryType::Loss->value])
            ->whereDoesntHave('reversedBy')
            ->distinct()
            ->pluck('investor_deal_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values();

        if ($dealIds->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($dealIds, $orderId): array {
            $written = [];

            foreach ($dealIds as $dealId) {
                $deal = InvestorDeal::query()->whereKey($dealId)->lockForUpdate()->first();

                if ($deal === null) {
                    continue;
                }

                foreach (($this->postShare)($deal, '0.00', AuditSubject::Order->value, $orderId, self::REASON) as $row) {
                    $written[] = $row;
                }
            }

            return $written;
        });
    }
}
