<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Customer\CustomerService;
use App\Domain\Customer\Exceptions\CustomerIsInactive;
use App\Domain\Customer\Exceptions\ShopDoesNotBelongToCustomer;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\DTOs\OrderData;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\AdditionalCostRequiresPermission;
use App\Domain\Order\Exceptions\DiscountRequiresPermission;
use App\Domain\Order\Exceptions\OrderNeedsAtLeastOneItem;
use App\Domain\Order\Exceptions\OutsourcedLineCannotShareAnOrder;
use App\Domain\Order\Exceptions\OutsourcedOrderNeedsAVendor;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\Money;
use App\Domain\Vendor\VendorService;
use Illuminate\Support\Facades\DB;

/**
 * Takes an order.
 *
 * The whole thing is one transaction: an order that exists with no lines, or with lines and no
 * opening row in its timeline, is worse than an order that failed outright.
 */
final class CreateOrder
{
    public function __construct(
        private readonly ResolveOrderDestination $resolveDestination,
        private readonly AddOrderItem $addItem,
        private readonly RecalculateOrderTotals $recalculate,
        private readonly RecordStatusTransition $record,
        private readonly CustomerService $customers,
        private readonly AddOrderDesign $addDesign,
        // Which road the order walks, read off its lines' categories — the flow twin of
        // `ResolveOrderDestination` above.
        private readonly ResolveOrderFlow $resolveFlow,
        // Who is making it, for the road where somebody else does — read through the Service
        // rather than the model, per RULES.md §3: cross-context work goes through the other
        // module's front door.
        private readonly VendorService $vendors,
    ) {}

    /**
     * [$initialStatus] is the door this order came through, and the only caller that passes
     * anything but the default is {@see RequestOrder} — the customer app's. It changes three
     * things and nothing else: the status written, the status the opening timeline row records,
     * and whether the vendor rule binds now or at acceptance.
     *
     * @throws OrderNeedsAtLeastOneItem
     * @throws DiscountRequiresPermission
     * @throws AdditionalCostRequiresPermission
     * @throws ShopDoesNotBelongToCustomer
     * @throws OutsourcedOrderNeedsAVendor
     * @throws OutsourcedLineCannotShareAnOrder
     */
    public function __invoke(
        OrderData $data,
        ?User $actor = null,
        OrderStatus $initialStatus = OrderStatus::New,
    ): Order {
        if ($data->items === null || $data->items === []) {
            throw OrderNeedsAtLeastOneItem::make();
        }

        $this->guardDiscount($data, $actor);
        $this->guardAdditionalCost($data, $actor);

        $customer = $this->customers->find($data->customerId);

        // Before anything is priced or written: a deactivated customer is one the shop has
        // stopped selling to, and «معطَّل» has to mean that here rather than only in the app
        // that hides the button.
        if (! $customer->is_active) {
            throw CustomerIsInactive::make((string) $customer->name);
        }

        $shopName = $this->resolveShop($data, (int) $customer->getKey());

        $destination = ($this->resolveDestination)($data->cityId, $data->regionId);

        // Looked up before the transaction like the shop and the city are, and for the same
        // reason: the name is a snapshot, and reading it here means the write below has every
        // value it needs in hand. A vendor that does not exist is already refused by the request.
        $vendorName = $data->vendorId === null
            ? null
            : (string) $this->vendors->find($data->vendorId)->name;

        return DB::transaction(function () use ($data, $destination, $customer, $shopName, $vendorName, $actor, $initialStatus): Order {
            $order = new Order;

            $order->fill([
                'customer_shop_id' => $data->customerShopId,
                // Snapshotted like the city and the region: a renamed branch must not rewrite
                // where an old order said it was going.
                'customer_shop_name' => $shopName,
                // Chosen from the vendor list, and its name kept beside it for the same reason
                // the branch above keeps one — see OUTSOURCED-PRODUCTS.md §5.
                'vendor_id' => $data->vendorId,
                'vendor_name' => $vendorName,
                'city_id' => $destination->cityId,
                'region_id' => $destination->regionId,
                'city_name' => $destination->cityName,
                'region_name' => $destination->regionName,
                'fulfilment_type' => $destination->fulfilmentType,
                'design_source' => $data->designSource,
                'recipient_name' => $data->recipientName,
                'recipient_phone' => $data->recipientPhone,
                'address_details' => $data->addressDetails,
                'notes' => $data->notes,
                'tracking_number' => $data->trackingNumber,
                // False when nothing was said, because «لم يُسأل» and «ليست مستعجلة» are the
                // same answer for a new order — unlike on an edit, where the silence means
                // «اتركها كما هي». See OrderData::$isUrgent.
                'is_urgent' => $data->isUrgent ?? false,
            ]);

            // Server-assigned, so they go on directly rather than through the fillable list —
            // a request that could post these could post any total it liked. `customer_id` is
            // here for a different reason: an order never changes hands, so leaving it
            // unfillable is what stops an update moving one between customers.
            $order->forceFill([
                'customer_id' => $customer->getKey(),
                'status' => $initialStatus,
                'design_fee' => $data->designSource->isChargeable() ? $data->designFee : '0.00',
                'delivery_price' => $destination->deliveryPrice,
                'discount' => $data->discount,
                // The three move together and are guarded together: an amount, why it was
                // charged, and the words beside it are one decision, not three fields.
                'additional_cost' => $data->additionalCost,
                'additional_cost_reason' => $data->additionalCostReason,
                'additional_cost_note' => $data->additionalCostNote,
                'placed_at' => now(),
                'created_by' => $actor?->getKey(),
            ]);

            $order->save();

            foreach ($data->items as $item) {
                ($this->addItem)($order, $item);
            }

            // **The artwork the customer walked in with.** Through {@see AddOrderDesign} like
            // every other version, so the rule that a design belongs to this customer is
            // enforced by the one place that knows it, and the version numbers are allocated the
            // same way — in the order the clerk picked them.
            //
            // Inside the transaction, which is the whole reason it happens here rather than in
            // a second request from the app: a design belonging to somebody else takes the order
            // down with it, instead of leaving one behind with a number, a customer, and none of
            // the files it was taken for.
            //
            // The order is «جديدة» at this point and that status accepts versions — see
            // {@see Order::designsAreEditable()}. Before this it did not, and the file could
            // only be recorded by walking the order through the designer's queue for work
            // nobody was doing.
            foreach ($data->designIds as $designId) {
                ($this->addDesign)($order, $designId);
            }

            ($this->recalculate)($order->load('items'));

            // The other thing the lines decide, read the moment they are all on: whether this
            // order has anything to design or print at all — see {@see ResolveOrderFlow}. Inside
            // the transaction and after the loop above, because it is an answer about the whole
            // set: an order is only put on the short road when *every* line is goods that are
            // already made.
            ($this->resolveFlow)($order);

            // **The lines must agree about whose bench they are made on.**
            // `ResolveOrderFlow` above wants them unanimous and falls back to the standard road
            // when they are not — right for «سادة» beside «مطبوعة», and wrong the moment a
            // وسيط line is in the order: that order would walk «قيد التصميم» and «قيد الطباعة»
            // for goods no press of ours touches, and `deductsStock()` would ask a warehouse for
            // goods that were never on a shelf of ours.
            //
            // Asked here for the reason the vendor rule below is: the answer is read off the
            // lines' categories, so it cannot be had until the lines exist. Inside the same
            // transaction, so a refused order is rolled back whole — and *both* doors come
            // through here, the clerk's and the app's, so there is one rule and no way round it.
            $this->guardOneBench($order);

            // **Asked here and nowhere earlier, because here is the first place it can be
            // asked.** Whether an order owes a vendor depends on the road it walks, and the road
            // is read off the lines that were written moments ago — a rule in the request would
            // have to guess at it from product ids. Inside the transaction, so an order that
            // should have named a vendor is rolled back whole rather than left standing without
            // one.
            //
            // **Except for a request, which is allowed to be incomplete.** An order the customer
            // app sent cannot name a vendor — the customer does not know we outsource anything,
            // and it is not their choice to make. So the rule binds at *acceptance* instead:
            // `ChangeOrderStatus` refuses «بانتظار المراجعة» → «جديدة» until a vendor is named,
            // which is the moment a real order comes into being. A request under review may be
            // incomplete; an order may not.
            if ($initialStatus !== OrderStatus::Requested
                && $order->production_flow->needsAVendor()
                && $order->vendor_id === null) {
                throw OutsourcedOrderNeedsAVendor::make();
            }

            // `from` is null exactly once per order, which is what makes "when was this taken"
            // a query on the timeline rather than a special case somewhere else.
            ($this->record)($order, null, $initialStatus, null, $actor);

            return $order->refresh();
        });
    }

    /**
     * Refuses an order whose lines are not all made in the same kind of place.
     *
     * **Unanimity about the group, not a limit on lines.** Several وسيط lines in one order are
     * fine: the road is unanimous and the vendor is named once, at acceptance. What cannot
     * happen is a وسيط line beside one that is not.
     *
     * A line whose product has no heading — the column is nullable — answers `in_house`, the
     * same assumption {@see ResolveOrderFlow} makes. The unknown case takes the road that asks
     * more of the shop, never the one that asks less.
     *
     * @throws OutsourcedLineCannotShareAnOrder
     */
    private function guardOneBench(Order $order): void
    {
        $order->loadMissing('items.product.productCategory.parent');

        $groups = $order->items
            ->map(fn (OrderItem $item): string => (
                $item->product?->productCategory?->productionMode() ?? ProductionMode::InHouse
            )->orderGroup())
            ->unique();

        if ($groups->count() > 1) {
            throw OutsourcedLineCannotShareAnOrder::make();
        }
    }

    /**
     * The field is hidden in the app for staff without the grant. This is the half that
     * matters: a hidden field is a suggestion, a refused request is a rule.
     *
     * @throws DiscountRequiresPermission
     */
    private function guardDiscount(OrderData $data, ?User $actor): void
    {
        if (bccomp($data->discount, '0', Money::SCALE) <= 0) {
            return;
        }

        if (! $actor?->can(PermissionName::DiscountOrders->value)) {
            throw DiscountRequiresPermission::make();
        }
    }

    /**
     * The other half of the same arrangement, and a grant of its own.
     *
     * Charging a customer more is not the same decision as charging them less, however alike
     * the two fields look — see {@see AdditionalCostRequiresPermission}.
     *
     * @throws AdditionalCostRequiresPermission
     */
    private function guardAdditionalCost(OrderData $data, ?User $actor): void
    {
        if (bccomp($data->additionalCost, '0', Money::SCALE) <= 0) {
            return;
        }

        if (! $actor?->can(PermissionName::AddOrderAdditionalCost->value)) {
            throw AdditionalCostRequiresPermission::make();
        }
    }

    /**
     * Checks the shop belongs to this customer, and hands back its name for the snapshot.
     *
     * Returning the row rather than just validating it: the name is needed a line later, and a
     * second query for something already in hand is the kind of N+1 that arrives one call site
     * at a time.
     *
     * @throws ShopDoesNotBelongToCustomer
     */
    private function resolveShop(OrderData $data, int $customerId): ?string
    {
        if ($data->customerShopId === null) {
            return null;
        }

        $shop = $this->customers->find($customerId)->shops()->find($data->customerShopId);

        if ($shop === null) {
            throw ShopDoesNotBelongToCustomer::make($data->customerShopId, $customerId);
        }

        return $shop->name;
    }
}
