<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\CancellationHasNoPriorStatus;
use App\Domain\Order\Exceptions\OrderIsNotCancelled;
use App\Domain\Order\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Puts a cancelled order back where it was, for a cancellation made by mistake.
 *
 * **Why this is not a status transition.** «إلغاء تام» is final on {@see OrderStatus}, and it
 * stays final: `allowedNext()` answers `[]` for it, `isFinal()` is what closes editing, the
 * progress bar stops where the work stopped, and `ChangeOrderStatus` refuses to move an order
 * out of a final status at all. Opening an arm from «إلغاء تام» in the map would say something
 * this business does not mean — that writing an order off is an ordinary step with ordinary
 * moves after it — and it would have to say *which* moves, which is a question with no answer:
 * the way back is not a status somebody chooses, it is the status the order was already in.
 *
 * So this is an **undo of one recorded move**, and it is the only writer of a status that does
 * not go through {@see ChangeOrderStatus}.
 *
 * **The destination is read, never chosen. That is the whole condition.** It comes from
 * `order_status_transitions` — the last move *into* «إلغاء تام», and its `from_status` — so the
 * order can only ever land back exactly where the cancellation took it from. A caller naming a
 * destination would be a second door into every status the map refuses to reach from a
 * cancellation, and the door would have no guard on it. An order whose timeline holds no such
 * move is refused rather than guessed at — see {@see CancellationHasNoPriorStatus}.
 *
 * **Stock is deliberately untouched, and that is a decision rather than an omission.** The
 * cancellation already credited the goods back — see {@see ReverseOrderStockDeduction} — and
 * this does not take them out again. Re-deducting would have to re-consume the exact cost
 * layers the reversal put back, on a shelf that has been sold from, revalued and counted in the
 * meantime, and it would do it without anybody looking at it; the business asked for the
 * opposite, and puts the shelf right by hand. `stock_deducted_at` is left standing for the same
 * reason it survives a reversal: it records that stock *did* leave this order once, which is
 * still true, and it is what stops a later move deducting a second time on its own.
 *
 * **The timeline gains a row, it does not lose one.** The cancellation stays in the order's
 * history with its reason, and this move is written above it as «إلغاء تام → استلام مكتب». What
 * *is* cleared is the pair of denormalised columns on `orders` — `cancelled_at` and
 * `cancellation_reason` — because those two describe the order's current state, and an order
 * standing in «استلام مكتب» while carrying «سبب الإلغاء» would put a red banner on the screen
 * about a cancellation that no longer holds, and would count itself in «أُلغيت هذا الأسبوع».
 */
final class ReinstateCancelledOrder
{
    public function __construct(private readonly RecordStatusTransition $record) {}

    /**
     * @param  string|null  $reason  A note, not an obligation: the cancellation it undoes is the
     *                               one move in this system made to justify itself, and asking
     *                               for a sentence again to correct a stray tap would stand
     *                               between somebody and the fix. Recorded on the row when given.
     *
     * @throws OrderIsNotCancelled
     * @throws CancellationHasNoPriorStatus
     */
    public function __invoke(Order $order, ?string $reason = null, ?User $actor = null): Order
    {
        if ($order->status !== OrderStatus::Cancelled) {
            throw OrderIsNotCancelled::make($order->status);
        }

        $target = $order->statusBeforeCancellation();

        if ($target === null) {
            throw CancellationHasNoPriorStatus::make();
        }

        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

        return DB::transaction(function () use ($order, $target, $reason, $actor): Order {
            $order->forceFill([
                'status' => $target,
                // Both, together: one says it was cancelled and the other says why, and an order
                // that is no longer cancelled must be carrying neither.
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ])->save();

            ($this->record)($order, OrderStatus::Cancelled, $target, $reason, $actor);

            return $order->refresh();
        });
    }
}
