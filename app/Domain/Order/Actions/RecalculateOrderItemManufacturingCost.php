<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\Order\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Adds a line's production-cost ledger up and writes the answer onto it.
 *
 * **The one place `order_items.labor_cost`/`overhead_cost` are ever written**, exactly the
 * arrangement {@see RecalculateOrderPayments} has with `orders.paid_amount`. Must run inside the
 * transaction that wrote the entry it is reacting to.
 *
 * **Rows are loaded rather than summed in SQL**, for the same reason `RecalculateOrderPayments`
 * gives: a line's ledger is a handful of entries, and a `SUM(CASE WHEN …)` would restate the
 * reversal rule a second time in a second language.
 *
 * `MachineRuntime` folds into `overhead_cost` — there is no third cache column for it. A line's
 * COGS distinguishes material from everything else it took to produce; the finer split between
 * machine time and general overhead lives on the `production_cost_entries` rows themselves for
 * whoever wants it, not on two more columns every reader would otherwise have to add together.
 *
 * **The losses fold into neither, and that is a rule rather than an omission.** `ScrapLoss` has
 * always been left out; `DeliveryLoss` joins it, and the test is now
 * {@see ManufacturingCostType::isLoss()} rather than one type named by hand — so a third kind of
 * loss cannot be added and quietly land in COGS. What was lost is *reported* beside the
 * statement, never subtracted inside it: the money is already in gross profit by construction,
 * because revenue fell while the cost frozen at «جاهزة» did not, and summing it here as well
 * would charge the same goods twice. See `ProfitAndLossSummaryQuery` and
 * PARTIAL-DELIVERY-DESIGN.md §3, Decision 2.
 */
final class RecalculateOrderItemManufacturingCost
{
    public function __invoke(OrderItem $item): OrderItem
    {
        $active = $this->activeEntriesFor($item);

        $item->forceFill([
            'labor_cost' => $this->sum($active, [ManufacturingCostType::Labor]),
            'overhead_cost' => $this->sum($active, [ManufacturingCostType::MachineRuntime, ManufacturingCostType::Overhead]),
        ])->save();

        return $item;
    }

    /**
     * Every entry against this line that is neither a reversal itself nor has been undone by one
     * — the net, currently-standing cost.
     *
     * @return Collection<int, ProductionCostEntry>
     */
    private function activeEntriesFor(OrderItem $item): Collection
    {
        return ProductionCostEntry::query()
            ->where('order_item_id', $item->getKey())
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversal')
            ->get();
    }

    /**
     * @param  Collection<int, ProductionCostEntry>  $entries
     * @param  list<ManufacturingCostType>  $types
     */
    private function sum(Collection $entries, array $types): ?string
    {
        $amounts = $entries->whereIn('cost_type', $types)->map(fn (ProductionCostEntry $entry) => (string) $entry->amount)->all();

        // Null rather than '0.00' when nothing has ever been recorded — the same "no correct
        // value yet" reasoning RecalculateOrderItemCost applies to a whole line's cogs.
        return $amounts === [] ? null : Money::sum(...$amounts);
    }
}
