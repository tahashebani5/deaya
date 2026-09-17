<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\TransitionFields;

/**
 * Corrects what left the warehouse, once the press knows what it actually used.
 *
 * **Two people weigh the same run, and the second one is right.** The warehouse weighs what it
 * pulls off the shelf on the way into «جاهزة للطباعة»; the press knows what the job actually
 * consumed by the time it reaches «جاهزة». Asking again there is not a duplicate question — it is
 * the only moment the true figure exists — and this action is what makes the shelf agree with it.
 *
 * **The correction itself is {@see RedrawOrderLineStock}**, which used to live in this class and
 * was lifted out of it when partial delivery needed the same two movements. Read that class for
 * why a correction is a reversal and a fresh draw rather than a delta movement; all four reasons
 * are still this action's reasons.
 *
 * What stays here is the only part that was ever about *this* move: deciding which lines the
 * press actually corrected, and refusing to touch the ones it did not.
 *
 * **A line whose figure did not move is skipped entirely** — no reversal, no re-draw, no row in
 * the ledger. That is the common case, and a ledger that recorded a pair of movements every time
 * somebody confirmed a number would bury the corrections that matter.
 */
final class RestateOrderStockDeduction
{
    public function __construct(private readonly RedrawOrderLineStock $redraw) {}

    /**
     * @param  array<string, mixed>  $fields  What the move asked for — see {@see TransitionFields}.
     */
    public function __invoke(Order $order, array $fields, int $employeeId): void
    {
        $order->items->loadMissing(['variant.stockItem', 'product.productCategory.parent']);

        foreach ($order->items as $item) {
            $corrected = $this->correctedQuantity($item, $fields);

            if ($corrected === null) {
                continue;
            }

            ($this->redraw)($order, $item, $corrected, $employeeId);
        }
    }

    /**
     * What this line should now read, or null when nothing about it moved.
     *
     * **Null is "leave it alone", and an absent field means the same thing.** A line whose units
     * agree is never asked — what was sold is what leaves — and a line the form did offer opens
     * holding its existing figure, so an untouched box comes back identical and lands here as
     * null. Only a number a person actually changed gets past this.
     *
     * @param  array<string, mixed>  $fields
     */
    private function correctedQuantity(OrderItem $item, array $fields): ?string
    {
        if (! $item->isStockedInAnotherUnit()) {
            return null;
        }

        $answer = $fields[TransitionFields::stockQuantityKey($item)] ?? null;

        if ($answer === null || $answer === '') {
            return null;
        }

        $was = (string) ($item->warehouse_quantity ?? $item->quantity);

        // Compared numerically, not as strings: «3.5» and «3.500» are the same weight, and a
        // string comparison would reverse and re-draw the whole line to record no change at all.
        return bccomp((string) $answer, $was, 3) === 0 ? null : (string) $answer;
    }
}
