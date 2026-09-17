<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\Money;

/**
 * Sums a line's cost components into one figure — the same relationship
 * {@see RecalculateOrderTotals} has with `line_total`/`items_total`.
 *
 * **Null until the line has actually been produced by somebody.** Two facts can say that it has,
 * and a line carries exactly one of them: `material_cost`, force-filled by {@see DeductOrderStock}
 * the moment stock leaves the warehouse, and `outsourcing_cost`, written by
 * {@see ApplyOutsourcingCosts} when a vendor hands the job over. Until one of them exists, `cogs`
 * staying null is the honest answer for a line that has not reached ready — not a zero pretending
 * to be a real cost.
 *
 * **The وسيط line never gets a `material_cost` at all**, and that is why the guard is an `||`
 * rather than a test on one column: its goods were never on a shelf of ours, so waiting for a
 * material cost would leave its `cogs` null forever and the P&L blind to the one cost that order
 * actually had.
 *
 * Called twice in the ordinary flow at ready — once by `DeductOrderStock` right after
 * `material_cost` is known, and again by `ApplyManufacturingRates` once `labor_cost`/
 * `overhead_cost` land moments later in the same transaction — which is fine: this is a pure
 * function of the three columns, not an accumulator.
 */
final class RecalculateOrderItemCost
{
    public function __invoke(OrderItem $item): OrderItem
    {
        if ($item->material_cost === null && $item->outsourcing_cost === null) {
            $item->forceFill(['cogs' => null])->save();

            return $item;
        }

        $item->forceFill([
            'cogs' => Money::sum(
                (string) ($item->material_cost ?? '0'),
                (string) ($item->labor_cost ?? '0'),
                (string) ($item->overhead_cost ?? '0'),
                (string) ($item->outsourcing_cost ?? '0'),
            ),
        ])->save();

        return $item;
    }
}
