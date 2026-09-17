<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Queries;

use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;

/**
 * What an order looks like to whoever is about to finance it: its shelves, its cost, and whether
 * it is still early enough to say who paid for it.
 *
 * **A query rather than a method on {@see PurchaseOrderService}, and that is not a style
 * preference.** `PurchaseOrderService` constructs `ReceivePurchaseOrder`, which constructs
 * `InvestorService`; an Investment action asking the service back would close a ring the
 * container resolves forever. A read class that touches nothing but its own models cannot.
 *
 * `total_cost` is the landed figure — `final_total_cost` per line, which already carries each
 * line's share of the shipping and customs typed on the order
 * ({@see AllocatePurchaseOrderAdditionalCosts}). It is what the funding screen holds the money
 * up against, so the man putting 30,000 into a 40,000 shipment sees both numbers.
 */
final class FundingSnapshotQuery
{
    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     is_fundable: bool,
     *     stock_item_ids: list<int>,
     *     line_costs: array<int, string>,
     *     total_cost: string,
     * }|null
     */
    public function __invoke(int $purchaseOrderId, bool $lock = false): ?array
    {
        $query = PurchaseOrder::query()->whereKey($purchaseOrderId);

        // **Read under the row's own lock when a decision hangs on it.** Two people funding one
        // order at the same moment would otherwise both read «not funded yet» and both pass; the
        // second insert would then die on the unique index as a 500 instead of being refused in
        // Arabic. The `RecordWalletEntry` discipline, applied to the document rather than the
        // wallet.
        $order = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($order === null) {
            return null;
        }

        $lines = $order->items()->get();

        $total = '0.00';
        $lineCosts = [];

        foreach ($lines as $line) {
            $cost = (string) ($line->final_total_cost ?? '0.00');

            $total = bcadd($total, $cost, 2);
            // Keyed by shelf, because funding is per line: a deal taking one line of three is
            // held up against that line's landed cost, not the lorry's.
            $lineCosts[(int) $line->stock_item_id] = $cost;
        }

        $shelves = $lines
            ->pluck('stock_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'id' => (int) $order->getKey(),
            'status' => $order->status->value,
            // Ownership is declared before the lorry arrives — that is the whole of «الموظف لا
            // يختار الصفقة». Once a single line has been received there are cost layers on the
            // shelf that this deal can never be stamped onto.
            'is_fundable' => $order->status === PurchaseOrderStatus::New && $shelves !== [],
            'stock_item_ids' => $shelves,
            'line_costs' => $lineCosts,
            'total_cost' => $total,
        ];
    }
}
