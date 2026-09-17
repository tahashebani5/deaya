<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Order\Actions\DeductOrderStock;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;

/**
 * What this order is short of, line by line, as the shelves stand right now.
 *
 * **A read, and only a read.** {@see DeductOrderStock} still refuses a deduction it cannot cover,
 * with the same message and the same exception it always threw; nothing about that changed to
 * make this possible. This exists so a screen can ask the question *before* pressing the button,
 * or right after being refused, and offer «سجّلها كنواقص» with the numbers already filled in —
 * instead of leaving a foreman to work out from «المتوفر ٢٧٠ والمطلوب ٣٠٠» what to type into
 * four boxes.
 *
 * **Per line, not per shelf — and the apportionment is the whole difficulty.** A shortfall is a
 * fact about a *pile*: two lines drawing on one stock item are short together, which is why
 * `StockShortfall` names the shelf rather than a product. A shortage is recorded against a
 * *line*, because that is what the invoice is cut from. Turning one into the other means deciding
 * which line goes short, and there is no answer the data can give.
 *
 * **So the rule is: the later lines go short first**, walking the order's own `sort_order`. The
 * earlier lines are filled from the shelf and the shortfall falls on what is left. That is
 * arbitrary in the same way any rule would be, and it is chosen because it is *predictable* — a
 * foreman reading the suggestion sees the same shape every time — and because the sum is what
 * actually matters: however it is split, the order is short by the same total, and a clerk may
 * move it between lines before saving. The suggestion is a starting point, never a decision.
 *
 * **Without a warehouse it answers from every shelf at once.** «نواقص» is reachable only from
 * «جديد», before `fulfillment_warehouse_id` exists, so that is the only figure available then —
 * and it is a weaker one, which `available_scope` says out loud rather than leaving the reader to
 * assume a single site.
 */
final class OrderStockShortfallQuery
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @return array{
     *     warehouse_id: int|null,
     *     available_scope: string,
     *     lines: list<array<string, mixed>>,
     *     is_short: bool,
     * }
     */
    public function __invoke(Order $order, ?int $warehouseId = null): array
    {
        $order->loadMissing('items.variant.stockItem');

        /** @var list<OrderItem> $items */
        $items = $order->items->sortBy('sort_order')->values()->all();

        $shelves = [];

        foreach ($items as $item) {
            $shelf = $item->variant?->stockItem;

            if ($shelf instanceof StockItem) {
                $shelves[(int) $item->getKey()] = $shelf;
            }
        }

        $balances = $this->balances($shelves, $warehouseId);

        // What is left of each pile as the walk proceeds — the earlier lines eat first, so the
        // shortfall lands on the later ones. See the class docblock for why that rule and not
        // another.
        $remainingOnShelf = $balances;

        $lines = [];
        $isShort = false;

        foreach ($items as $item) {
            $lineId = (int) $item->getKey();
            $shelf = $shelves[$lineId] ?? null;

            if ($shelf === null) {
                // A size with no shelf behind it cannot be weighed against one. Reported with a
                // null balance rather than dropped: a line missing from the answer would read as
                // «this one is fine».
                $lines[] = $this->line($item, null, '0.000');

                continue;
            }

            $shelfId = (int) $shelf->getKey();
            $required = $item->producedQuantity();
            $available = $remainingOnShelf[$shelfId] ?? '0.000';

            $short = bcsub($required, $available, 3);
            $short = bccomp($short, '0', 3) > 0 ? $short : '0.000';

            // Whatever this line took, the next line drawing on the same pile cannot take again.
            $remainingOnShelf[$shelfId] = bccomp($available, $required, 3) > 0
                ? bcsub($available, $required, 3)
                : '0.000';

            $isShort = $isShort || bccomp($short, '0', 3) > 0;

            $lines[] = $this->line($item, $balances[$shelfId] ?? '0.000', $short);
        }

        return [
            'warehouse_id' => $warehouseId,
            'available_scope' => $warehouseId === null ? 'all_warehouses' : 'warehouse',
            'lines' => $lines,
            'is_short' => $isShort,
        ];
    }

    /**
     * @param  array<int, StockItem>  $shelves
     * @return array<int, string>
     */
    private function balances(array $shelves, ?int $warehouseId): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (StockItem $shelf): int => (int) $shelf->getKey(),
            $shelves,
        )));

        return $warehouseId === null
            ? $this->inventory->onHandFor($ids)
            : $this->inventory->balancesFor($warehouseId, $ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(OrderItem $item, ?string $available, string $short): array
    {
        return [
            'line_id' => (int) $item->getKey(),
            // The line's own snapshot, so an order refers to a size by what it was written with.
            'name' => trim($item->product_name.' — '.$item->variant_label),
            'variant_label' => (string) $item->variant_label,
            'unit' => $item->stockUnit()->value,
            'unit_label' => $item->stockUnit()->label(),
            'required' => $item->producedQuantity(),
            'available' => $available,
            // What to put in this line's «الناقص من …» box. Zero where the shelf covers it.
            'suggested_shortage' => $short,
        ];
    }
}
