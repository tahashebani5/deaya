<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Exceptions\ReadyMessageNeedsAReadyOrder;
use App\Domain\Order\Models\Order;

/**
 * Records that the customer has been told their order is ready — or takes that back.
 *
 * **The only writer of `ready_message_sent_at`**, and it writes the two columns together: a stamp
 * without a name says the work was done by nobody, and a name without a stamp says it was done at
 * no particular time. Clearing does the same in reverse, so an order can never be left half
 * marked.
 *
 * **Both directions in one action, because they are one decision made twice.** The tick is a
 * daily job and the untick is the correction of a stray tap on it; splitting them would be two
 * classes enforcing the same single rule, and the second copy is the one that forgets it. The
 * audit trail keeps both movements — `Auditable` logs the columns like any other — so «مَن
 * تراجع؟» is answerable even though the columns themselves are empty afterwards.
 *
 * Nothing is derived here and nothing else moves: the order's status, its money and its stock are
 * untouched. This is a note about a message somebody sent on their own phone.
 */
final class MarkReadyMessageSent
{
    /**
     * @param  bool  $sent  true stamps now and the actor; false clears both.
     *
     * @throws ReadyMessageNeedsAReadyOrder
     */
    public function __invoke(Order $order, bool $sent, ?User $actor = null): Order
    {
        // Guarded here rather than only in the request, so a console command and a future import
        // meet the same rule — the reason every other order rule lives in the domain.
        if (! $order->readyMessageApplies()) {
            throw ReadyMessageNeedsAReadyOrder::make();
        }

        // `forceFill`: neither column is fillable, deliberately. A request that could post them
        // could put a colleague's name against work they never did.
        $order->forceFill([
            'ready_message_sent_at' => $sent ? now() : null,
            'ready_message_sent_by' => $sent ? $actor?->getKey() : null,
        ])->save();

        return $order;
    }
}
