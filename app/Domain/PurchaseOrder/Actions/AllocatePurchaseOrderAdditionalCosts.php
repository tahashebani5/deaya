<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Actions;

use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use App\Domain\PurchaseOrder\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Spreads an order's additional costs (delivery, unloading, customs, ...) across its own lines,
 * proportional to each line's share of the order's base cost, and derives every cost-shaped
 * figure a line carries from the result.
 *
 * The one place that decides a line's `base_unit_cost`, `allocated_additional_cost`,
 * `final_unit_cost` and `final_total_cost` — never trusted from the request, the same treatment
 * `total_cost`/`unit` already got before this feature existed. Called after a purchase order's
 * lines and additional costs are both persisted, and before {@see RecalculatePurchaseOrderTotal}
 * sums the result into the order's own totals.
 *
 * Allocation is done in integer cents, entirely through bcmath — never a float, per RULES.md §8
 * — using the **largest-remainder method** (Hamilton's method): each line's raw share is floored,
 * then the leftover cents (the difference between the sum of the floors and the true total) are
 * handed out one at a time to the lines with the largest remainder, ties broken by line id. A
 * naive per-line round would leave the allocated shares summing to a cent more or less than the
 * additional-costs total on almost any order with more than one line; this guarantees they never
 * do.
 *
 * A line with zero `base_total_cost` has no meaningful share of the base cost to divide by — if
 * every line on the order is free and there is still something to distribute, it is split
 * equally across lines instead of being left unplaced.
 */
final class AllocatePurchaseOrderAdditionalCosts
{
    public function __invoke(PurchaseOrder $order): void
    {
        $items = $order->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $allocatedCents = $this->allocateCents($this->totalAdditionalCostCents($order), $items);

        foreach ($items as $item) {
            $allocated = $this->centsToDecimal($allocatedCents[$item->getKey()]);
            $baseUnitCost = Money::roundTo(
                bcdiv((string) $item->base_total_cost, (string) $item->quantity_ordered, 10),
                3,
            );
            $finalTotalCost = Money::sum((string) $item->base_total_cost, $allocated);
            $finalUnitCost = Money::roundTo(bcdiv($finalTotalCost, (string) $item->quantity_ordered, 10), 3);

            $item->forceFill([
                'base_unit_cost' => $baseUnitCost,
                'allocated_additional_cost' => $allocated,
                'final_unit_cost' => $finalUnitCost,
                'final_total_cost' => $finalTotalCost,
            ])->save();
        }
    }

    private function totalAdditionalCostCents(PurchaseOrder $order): string
    {
        $amounts = $order->additionalCosts()->get()->map(fn ($cost) => (string) $cost->amount)->all();

        return $amounts === [] ? '0' : $this->decimalToCents(Money::sum(...$amounts));
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $items
     * @return array<int, string> Cents allocated per item id.
     */
    private function allocateCents(string $totalCents, Collection $items): array
    {
        if (bccomp($totalCents, '0', 0) === 0) {
            return $items->mapWithKeys(fn (PurchaseOrderItem $item) => [$item->getKey() => '0'])->all();
        }

        $sumBaseCostCents = $this->decimalToCents(
            Money::sum(...$items->map(fn (PurchaseOrderItem $item) => (string) $item->base_total_cost)->all()),
        );

        // A line with nothing to weigh by (every line free) shares equally rather than by an
        // undefined proportion.
        $usesEqualSplit = bccomp($sumBaseCostCents, '0', 0) === 0;
        $denominator = $usesEqualSplit ? (string) $items->count() : $sumBaseCostCents;

        $floors = [];
        $remainders = [];
        $sumFloors = '0';

        foreach ($items as $item) {
            $weight = $usesEqualSplit ? '1' : $this->decimalToCents((string) $item->base_total_cost);
            $numerator = bcmul($totalCents, $weight, 0);

            $floors[$item->getKey()] = bcdiv($numerator, $denominator, 0);
            $remainders[$item->getKey()] = bcmod($numerator, $denominator);
            $sumFloors = bcadd($sumFloors, $floors[$item->getKey()], 0);
        }

        $leftover = (int) bcsub($totalCents, $sumFloors, 0);

        // Largest remainder first; ties broken by line id, ascending, for a deterministic result.
        $order = $items->pluck('id')
            ->sort(fn (int $a, int $b) => bccomp($remainders[$b], $remainders[$a], 0) ?: $a <=> $b)
            ->values();

        $shares = $floors;

        foreach ($order->take($leftover) as $id) {
            $shares[$id] = bcadd($shares[$id], '1', 0);
        }

        return $shares;
    }

    private function decimalToCents(string $value): string
    {
        return bcmul($value, '100', 0);
    }

    private function centsToDecimal(string $cents): string
    {
        return bcdiv($cents, '100', 2);
    }
}
