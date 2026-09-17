<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Actions;

use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Models\Shortage;
use Illuminate\Support\Facades\DB;

/**
 * Stops chasing what an order no longer needs — a cancellation, a delete, or a line that went
 * away.
 *
 * **Two endings, and which one a row gets is decided by whether money was ever spent on it.**
 *
 * - Nothing supplied: soft-deleted. Nobody chased it and nothing was bought; leaving it on the
 *   board would be work that no longer exists, competing for attention with work that does.
 * - Something supplied: moved to «غير متوفر» and kept. The money left the till and the goods were
 *   really bought, whatever later became of the paperwork.
 *
 * **«مكتمل» is never touched by any of it.** «الاحتفاظ بالنواقص المكتملة كسجلٍّ تاريخي» is an
 * explicit requirement, and underneath it the same reason as above: a completed shortage is a
 * purchase that happened, and a record that erases a real expense because a different row was
 * archived is a record that lies.
 *
 * **And no supply is ever reversed here** — a dated decision, not an omission. `DeleteOrder`
 * reverses *customer* payments because `ProfitAndLossSummaryQuery` reads revenue through the
 * soft-delete scope while reading cash outside it, so an untreated paid order makes one report
 * describe two different sets of orders. No such asymmetry exists here: the cash really left, the
 * goods really exist, and reversing inside a delete button would be exactly the «قيد محاسبي لم
 * يخترْه أحد» that ORDER-DELETE-AND-ARCHIVE §٢٫١ argues against everywhere else. See
 * SHORTAGES-DESIGN §٧٫٣.
 */
final class CloseShortagesForOrder
{
    /**
     * @param  list<int>|null  $exceptLineIds  lines that still exist, and so are the sync's to
     *                                         reconcile rather than this one's to sweep. Null
     *                                         means the whole order is ending — a cancellation
     *                                         or a delete — and every open row goes.
     */
    public function __invoke(int $orderId, ?array $exceptLineIds = null): void
    {
        DB::transaction(function () use ($orderId, $exceptLineIds): void {
            $query = Shortage::query()
                ->where('order_id', $orderId)
                ->where('status', '!=', ShortageStatus::Completed);

            if ($exceptLineIds !== null) {
                // A manual shortage has no line and so is not swept by elimination — it belongs
                // to nobody's order lines and outlives every edit to them.
                $query->whereNotNull('order_item_id')
                    ->whereNotIn('order_item_id', $exceptLineIds);
            }

            foreach ($query->lockForUpdate()->get() as $shortage) {
                if (bccomp((string) $shortage->supplied_quantity, '0', 3) > 0) {
                    // Kept, and marked as no longer being chased. The supplies stay exactly as
                    // they are — see the class docblock.
                    $shortage->forceFill(['status' => ShortageStatus::Unavailable])->save();

                    continue;
                }

                $shortage->delete();
            }
        });
    }
}
