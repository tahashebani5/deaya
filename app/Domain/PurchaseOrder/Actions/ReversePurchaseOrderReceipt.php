<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Actions;

use App\Domain\PurchaseOrder\DTOs\ReversePurchaseOrderReceiptData;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderReceiptNotReversible;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use App\Domain\Vendor\Models\StockArrival;
use App\Domain\Vendor\VendorService;
use Illuminate\Support\Facades\DB;

/**
 * Undoes what {@see ReceivePurchaseOrder} did, for a receipt somebody entered in error.
 *
 * **Exactly the receive path, run backwards, and through the same front door.**
 * {@see VendorService::reverseStockArrival()} is the only path taken to actually move the stock —
 * RULES.md §3, the same rule the receive side answers to — and what happens here is layered
 * strictly on top, in the same transaction: each line's `quantity_received` comes back down by
 * what the shipment carried, and the order's status returns to where the receipt found it.
 *
 * **Which is `arrived`, not `new`.** A shipment demonstrably turned up against this order, so
 * «قيد الاستلام» is the truthful state to put it back into whichever of the two open statuses it
 * was actually received from — and recovering that distinction would need a column written on
 * every receipt to answer a question nobody acts on. The order is open again and may be received
 * against again, which is the whole point.
 *
 * **A receipt is the order's only one**, so there is exactly one arrival to take back — see
 * {@see ReceivePurchaseOrder}, where `completed` refuses a second shipment. The query below still
 * asks for the un-reversed one rather than assuming, because that is what makes reversing twice
 * a clean refusal instead of a second withdrawal against layers already at zero.
 *
 * **The window, and the three guards no grant waives, all live in Vendor** — see
 * `ReverseStockArrival`. This action deliberately knows none of them: whether the receipt may be
 * undone is a fact about the document, and this module's business is only what its own paperwork
 * has to say afterwards.
 *
 * @throws PurchaseOrderReceiptNotReversible
 */
final class ReversePurchaseOrderReceipt
{
    public function __construct(private readonly VendorService $vendors) {}

    public function __invoke(PurchaseOrder $order, ReversePurchaseOrderReceiptData $data): PurchaseOrder
    {
        if ($order->status !== PurchaseOrderStatus::Completed) {
            throw PurchaseOrderReceiptNotReversible::make($order->status);
        }

        return DB::transaction(function () use ($order, $data): PurchaseOrder {
            $arrival = $this->reversibleArrival($order);

            // Locked for the rest of the transaction, the same reasoning ReceivePurchaseOrder
            // documents: two callers reading the same quantity_received and both writing a
            // figure computed from it would leave the line describing a shipment that never was.
            $items = $order->items()->lockForUpdate()->get()->keyBy('stock_item_id');

            $arrival = $this->vendors->reverseStockArrival($arrival, $data->toArrivalData());

            foreach ($arrival->items as $line) {
                /** @var PurchaseOrderItem|null $item */
                $item = $items->get($line->stock_item_id);

                // Null only if the ordering line was removed after the receipt, which the
                // status machine already forbids — an order is editable in `new` alone. The
                // stock has still gone back either way; there is simply no line left to correct.
                if ($item === null) {
                    continue;
                }

                $item->quantity_received = bcsub(
                    (string) $item->quantity_received, (string) $line->quantity, 3,
                );
                $item->save();
            }

            $order->status = PurchaseOrderStatus::Arrived;
            $order->save();

            return $order;
        });
    }

    /**
     * The one receipt on this order that has not already been taken back.
     *
     * @throws PurchaseOrderReceiptNotReversible
     */
    private function reversibleArrival(PurchaseOrder $order): StockArrival
    {
        $arrival = $order->stockArrivals()
            ->whereNull('reversed_at')
            ->with('items')
            ->orderByDesc('id')
            ->first();

        if ($arrival === null) {
            throw PurchaseOrderReceiptNotReversible::make($order->status);
        }

        return $arrival;
    }
}
