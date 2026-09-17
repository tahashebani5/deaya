<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Actions;

use App\Domain\Investor\InvestorService;
use App\Domain\PurchaseOrder\DTOs\ReceivePurchaseOrderData;
use App\Domain\PurchaseOrder\DTOs\ReceivePurchaseOrderItemData;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderHasNoWarehouse;
use App\Domain\PurchaseOrder\Exceptions\PurchaseOrderNotReceivable;
use App\Domain\PurchaseOrder\Exceptions\StockItemNotOnPurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use App\Domain\PurchaseOrder\Support\Money;
use App\Domain\Vendor\DTOs\StockArrivalData;
use App\Domain\Vendor\DTOs\StockArrivalItemData;
use App\Domain\Vendor\Models\StockArrival;
use App\Domain\Vendor\VendorService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receives a shipment against a purchase order: posts it through Vendor's own front door, then
 * applies what it means for this order's lines and status.
 *
 * **This class never writes a balance, and never writes `stock_arrival_items` itself.**
 * {@see VendorService::recordStockArrival()} — the same call the plain
 * `POST /stock-arrivals` endpoint makes — is the only path taken to actually move stock, per
 * RULES.md §3: cross-context work goes through the other module's Service, never around it. What
 * happens here is layered strictly on top, in the same transaction: a line's
 * `quantity_received` climbs by what this shipment carried, and the order's status is
 * recomputed from the result.
 *
 * **A shipment larger than the order is booked in whole.** Suppliers overship — a run of bags
 * comes off the machine heavy and the whole lot arrives — and the goods are on the shelf whether
 * or not the paperwork expected them, so refusing the receipt would only leave the ledger
 * describing a warehouse that does not exist. The surplus enters stock at the line's own landed
 * unit cost, so what the order agreed per unit is what the extra units are valued at; the line
 * then owes nothing and reports the surplus separately, and the order completes.
 *
 * **A shipment smaller than the order also completes it, and the shortfall is reported rather
 * than left holding the order open.** The owner's rule, on 2026-09-06: «تسجيل شحنات دوما يخليها
 * مكتملة حتى لو في نواقص وتسجل كنواقص». A delivery is the event that closes the order — what the
 * supplier failed to send is a fact about that delivery, not a reason to keep asking whether more
 * is coming. What is still owing stays on the line as `quantity_ordered - quantity_received` and
 * is published as `quantity_remaining` beside `quantity_over_received`, so «نواقص» is a number
 * every screen can read off the order rather than a status it has to infer.
 *
 * **So the receipt is the order's last act.** `completed` is refused by the guard above, which
 * makes the first shipment the only one an order takes: goods that turn up afterwards are their
 * own event and are posted through `POST /stock-arrivals`, unattached to a purchase order.
 *
 * Each line's `final_unit_cost` — the base cost plus its allocated share of the order's
 * additional costs, from {@see AllocatePurchaseOrderAdditionalCosts} — also travels into the
 * {@see StockArrivalItemData} built here, so a shipment's landed cost (and the FIFO stock batch
 * it opens) includes delivery/customs/unloading, not just the vendor's quoted price. Priced
 * against what *this* shipment delivered, which can be less than the order line's own
 * `final_total_cost` on a partial receipt — so the vendor module still never decides a cost, only
 * records the one this module already agreed to.
 *
 * @throws PurchaseOrderNotReceivable
 * @throws PurchaseOrderHasNoWarehouse
 * @throws StockItemNotOnPurchaseOrder
 */
final class ReceivePurchaseOrder
{
    public function __construct(
        private readonly VendorService $vendors,
        private readonly InvestorService $investors,
    ) {}

    public function __invoke(PurchaseOrder $order, ReceivePurchaseOrderData $data): StockArrival
    {
        if (! in_array($order->status, [PurchaseOrderStatus::New, PurchaseOrderStatus::Arrived], true)) {
            throw PurchaseOrderNotReceivable::make($order->status);
        }

        if ($order->warehouse_id === null) {
            throw PurchaseOrderHasNoWarehouse::make((int) $order->getKey());
        }

        return DB::transaction(function () use ($order, $data): StockArrival {
            // Locked for the rest of the transaction so two concurrent receipts against the
            // same order cannot both read the same quantity_received and both write a total
            // computed from it — the same reasoning ApplyStockChange locks a balance row for.
            $items = $order->items()->lockForUpdate()->get()->keyBy('stock_item_id');

            foreach ($data->items as $line) {
                $this->guardLine($order, $items, $line);
            }

            $arrival = $this->vendors->recordStockArrival(new StockArrivalData(
                vendorId: $order->vendor_id,
                warehouseId: $order->warehouse_id,
                receivedBy: $data->receivedBy,
                items: array_map(
                    function (ReceivePurchaseOrderItemData $line) use ($items, $order): StockArrivalItemData {
                        /** @var PurchaseOrderItem $orderedLine */
                        $orderedLine = $items->get($line->stockItemId);
                        $unitCost = $orderedLine->final_unit_cost === null ? null : (string) $orderedLine->final_unit_cost;
                        $funding = $this->investors->dealForSupply((int) $order->getKey(), $line->stockItemId);

                        return new StockArrivalItemData(
                            stockItemId: $line->stockItemId,
                            quantity: $line->quantity,
                            unitCost: $unitCost,
                            // Priced against what *this* shipment delivered, not the order line's
                            // own total — a partial receipt costs less than the whole line does.
                            totalCost: $unitCost === null
                                ? null
                                : Money::round(bcmul($unitCost, $line->quantity, 6)),
                            // **The whole of «الموظف لا يختار الصفقة أبداً».** One question to
                            // Investment per line, answered from a claim somebody made before the
                            // goods left the supplier. Null for everything the company bought for
                            // itself. The receiving screen's body is unchanged and the storekeeper
                            // sees no field.
                            investorDealId: $funding?->dealId,
                            // Frozen onto the layer beside the deal that owns it: what the press
                            // will pay for these bags the day it prints on them.
                            printingSalePrice: $funding?->printingSalePrice,
                        );
                    },
                    $data->items,
                ),
                invoiceNumber: $data->invoiceNumber,
                notes: $data->notes,
                purchaseOrderId: $order->getKey(),
            ));

            foreach ($data->items as $line) {
                /** @var PurchaseOrderItem $item */
                $item = $items->get($line->stockItemId);
                $item->quantity_received = bcadd((string) $item->quantity_received, $line->quantity, 3);
                $item->save();
            }

            // Unconditional — see the class docblock. A short delivery closes the order just as
            // a full one does, and what is missing is read off the lines, not off the status.
            $order->status = PurchaseOrderStatus::Completed;
            $order->save();

            return $arrival;
        });
    }

    /**
     * The line has to *be* on the order — a shipment naming a shelf nobody ordered is a mistyped
     * payload, not a delivery. How much it carried is not questioned: over-delivery is a normal
     * thing for a supplier to do on one size, and refusing it would leave real stock unbooked.
     *
     * @param  Collection<int, PurchaseOrderItem>  $items  Keyed by stock_item_id.
     *
     * @throws StockItemNotOnPurchaseOrder
     */
    private function guardLine(PurchaseOrder $order, Collection $items, ReceivePurchaseOrderItemData $line): void
    {
        if (! $items->has($line->stockItemId)) {
            throw StockItemNotOnPurchaseOrder::make($line->stockItemId, (int) $order->getKey());
        }
    }
}
