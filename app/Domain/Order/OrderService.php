<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Identity\Models\User;
use App\Domain\Order\Actions\AddOrderDesign;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Actions\ConfirmDepositReceipt;
use App\Domain\Order\Actions\CreateManufacturingCostRate;
use App\Domain\Order\Actions\CreateOrder;
use App\Domain\Order\Actions\MarkReadyMessageSent;
use App\Domain\Order\Actions\RecordOrderPayment;
use App\Domain\Order\Actions\RecordScrapLoss;
use App\Domain\Order\Actions\RefundOrderPayment;
use App\Domain\Order\Actions\ReinstateCancelledOrder;
use App\Domain\Order\Actions\ReverseOrderPayment;
use App\Domain\Order\Actions\ReviewOrderDesign;
use App\Domain\Order\Actions\SetOrderShortages;
use App\Domain\Order\Actions\UpdateManufacturingCostRate;
use App\Domain\Order\Actions\UpdateOrder;
use App\Domain\Order\Actions\WriteOffOrderBalance;
use App\Domain\Order\DTOs\ManufacturingCostRateData;
use App\Domain\Order\DTOs\OrderData;
use App\Domain\Order\DTOs\OrderLineShortage;
use App\Domain\Order\DTOs\OrderPaymentData;
use App\Domain\Order\Enums\OrderDesignStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\ShortageRevision;
use App\Domain\Order\Exceptions\DepositConfirmationNeedsADeposit;
use App\Domain\Order\Exceptions\DepositConfirmationNeedsASecondPerson;
use App\Domain\Order\Exceptions\ReadyMessageNeedsAReadyOrder;
use App\Domain\Order\Exceptions\ScrapRequiresAnActor;
use App\Domain\Order\Models\ManufacturingCostRate;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDesign;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\Order\Queries\ManufacturingCostRateFilters;
use App\Domain\Order\Queries\ManufacturingCostRateListQuery;
use App\Domain\Order\Queries\OrderCountQuery;
use App\Domain\Order\Queries\OrderFilters;
use App\Domain\Order\Queries\OrderListQuery;
use App\Domain\Order\Queries\OrderPaymentStatusCountsQuery;
use App\Domain\Order\Queries\OrderStatusCountsQuery;
use App\Domain\Order\Queries\OrderStockShortfallQuery;
use App\Domain\Order\Queries\OrderTotalsQuery;
use App\Domain\Order\Queries\ProfitAttributionQuery;
use App\Domain\Order\Queries\StockPurchaseAttributionQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Order module's public front door.
 *
 * Order depends on Catalog, Customer, Delivery and Identity, and reaches every one of them
 * through its service rather than its models. None of the four knows this module exists — which
 * is what lets an order gain a concept without the catalogue or the delivery map having to be
 * told.
 */
class OrderService
{
    public function __construct(
        private readonly CreateOrder $createOrder,
        private readonly UpdateOrder $updateOrder,
        private readonly ChangeOrderStatus $changeStatus,
        // The only writer of a status that does not go through the one above — see its docblock
        // for why undoing a cancellation is not a move on the map.
        private readonly ReinstateCancelledOrder $reinstateOrder,
        private readonly SetOrderShortages $setShortages,
        private readonly MarkReadyMessageSent $markReadyMessageSent,
        private readonly ConfirmDepositReceipt $confirmDepositReceipt,
        private readonly AddOrderDesign $addDesign,
        private readonly ReviewOrderDesign $reviewDesign,
        private readonly RecordOrderPayment $recordPayment,
        private readonly RefundOrderPayment $refundPayment,
        private readonly ReverseOrderPayment $reversePayment,
        private readonly WriteOffOrderBalance $writeOffBalance,
        private readonly CreateManufacturingCostRate $createManufacturingCostRate,
        private readonly UpdateManufacturingCostRate $updateManufacturingCostRate,
        private readonly RecordScrapLoss $recordScrapLoss,
        private readonly OrderListQuery $listQuery,
        private readonly ProfitAttributionQuery $profitAttribution,
        private readonly StockPurchaseAttributionQuery $stockPurchaseAttribution,
        private readonly OrderCountQuery $count,
        private readonly OrderStockShortfallQuery $stockShortfall,
        private readonly OrderStatusCountsQuery $statusCounts,
        private readonly OrderPaymentStatusCountsQuery $paymentStatusCounts,
        private readonly OrderTotalsQuery $totals,
        private readonly ManufacturingCostRateListQuery $manufacturingCostRateListQuery,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(OrderFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->listQuery)($filters, $perPage);
    }

    /**
     * One customer's own orders, newest first — what the customer app's «طلباتي» reads.
     *
     * **Here rather than as a relation on `Customer`.** Dependencies run one way: `Order` may
     * depend on `Customer` and never the reverse, so a `$customer->orders()` would point the
     * Customer context at this one. The confinement is the `where` below, and it is the whole of
     * what keeps one customer out of another's orders — the endpoints carry no customer id at
     * all, so nothing else could be.
     *
     * [$openOnly] narrows to the orders still moving. The statuses are passed in rather than
     * decided here, because «still moving» is a question in the *customer's* vocabulary — see
     * `CustomerOrderStage` — and this context does not know that vocabulary.
     *
     * @param  list<string>|null  $onlyStatuses
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginateForCustomer(
        int $customerId,
        int $perPage = 15,
        ?array $onlyStatuses = null,
    ): LengthAwarePaginator {
        return Order::query()
            ->where('customer_id', $customerId)
            ->when($onlyStatuses !== null, fn ($q) => $q->whereIn('status', $onlyStatuses))
            ->with('items')
            ->withCount('items')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * One of a given customer's orders, or a 404.
     *
     * **Scoped in the query, never checked after the fact.** `Order::find()` followed by an
     * ownership test is the shape that goes wrong the day somebody forgets the test; a `where`
     * on the customer means a foreign id never becomes an object.
     */
    public function findForCustomer(int $customerId, int $orderId): Order
    {
        return Order::query()
            ->where('customer_id', $customerId)
            ->with(['items', 'transitions'])
            ->findOrFail($orderId);
    }

    /**
     * How many orders sit in each status, under the same filters as the list beside it.
     *
     * @return array<string, int>
     */
    public function statusCounts(OrderFilters $filters): array
    {
        return ($this->statusCounts)($filters);
    }

    /**
     * How many orders answer one question, as a single number.
     *
     * The home board's «بانتظار رسالة الجاهزية» is this, run over the same {@see OrderFilters}
     * the screen behind the card runs — see {@see OrderCountQuery} for why that matters.
     */
    public function count(OrderFilters $filters): int
    {
        return ($this->count)($filters);
    }

    /**
     * How many orders stand unpaid, part-paid, paid and overpaid, under the same filters.
     *
     * A second axis beside {@see statusCounts()} and never folded into it: «جاهزة» says nothing
     * about whether an order is paid, which is the whole reason the two were never merged into
     * one enum.
     *
     * @return array<string, int>
     */
    public function paymentStatusCounts(OrderFilters $filters): array
    {
        return ($this->paymentStatusCounts)($filters);
    }

    /**
     * How much work has come in: ever, today, and this month.
     *
     * Unfiltered on purpose — these are the shop's own numbers, not a view of a list somebody
     * is looking at.
     *
     * @return array{total: int, daily: int, monthly: int}
     */
    public function totals(): array
    {
        return ($this->totals)();
    }

    public function create(OrderData $data, ?User $actor = null): Order
    {
        return ($this->createOrder)($data, $actor);
    }

    public function update(Order $order, OrderData $data, ?User $actor = null): Order
    {
        return ($this->updateOrder)($order, $data, $actor);
    }

    /**
     * @param  array<string, mixed>  $fields  What the move asked for — artwork, and whatever a
     *                                        later path adds. See {@see TransitionFields}.
     */
    public function changeStatus(
        Order $order,
        OrderStatus $target,
        ?string $reason = null,
        ?User $actor = null,
        array $fields = [],
    ): Order {
        return ($this->changeStatus)($order, $target, $reason, $actor, $fields);
    }

    /**
     * Undo a cancellation made by mistake, putting the order back exactly where it stood.
     *
     * The destination is read from the order's own timeline rather than accepted from the
     * caller, and no stock moves — see {@see ReinstateCancelledOrder}.
     */
    public function reinstate(Order $order, ?string $reason = null, ?User $actor = null): Order
    {
        return ($this->reinstateOrder)($order, $reason, $actor);
    }

    /**
     * Correct what is missing from an order, and the invoice with it.
     *
     * @param  array<int|string, mixed>  $shortages  line id → what is missing from it.
     * @param  ShortageRevision  $reason  defaults to a correction, which is what the order screen
     *                                    is: the two status paths name their own — see the enum.
     */
    public function setShortages(
        Order $order,
        array $shortages,
        ?User $actor = null,
        ShortageRevision $reason = ShortageRevision::Corrected,
    ): Order {
        return ($this->setShortages)($order, $shortages, $actor, $reason);
    }

    /**
     * Records that the customer was told their order is ready — or takes that back.
     *
     * @throws ReadyMessageNeedsAReadyOrder
     */
    public function markReadyMessageSent(Order $order, bool $sent, ?User $actor = null): Order
    {
        return ($this->markReadyMessageSent)($order, $sent, $actor);
    }

    /**
     * Records that somebody checked the account and the عربون is there — or takes that back.
     *
     * @throws DepositConfirmationNeedsADeposit
     * @throws DepositConfirmationNeedsASecondPerson
     */
    public function confirmDepositReceipt(Order $order, bool $received, ?User $actor = null): Order
    {
        return ($this->confirmDepositReceipt)($order, $received, $actor);
    }

    public function addDesign(Order $order, int $customerDesignId, ?string $notes = null): OrderDesign
    {
        return ($this->addDesign)($order, $customerDesignId, $notes);
    }

    public function reviewDesign(
        Order $order,
        OrderDesign $design,
        OrderDesignStatus $verdict,
        ?string $reason = null,
        ?User $actor = null,
    ): OrderDesign {
        return ($this->reviewDesign)($order, $design, $verdict, $reason, $actor);
    }

    /**
     * An order's money ledger, oldest first.
     *
     * Not paginated: an order's entries are counted on one hand, and a page boundary through a
     * ledger would hide the reversal that explains the entry above it.
     *
     * @return Collection<int, OrderPayment>
     */
    public function payments(Order $order): Collection
    {
        return $order->payments()->with(['recorder', 'reversal', 'reversedPayment'])->get();
    }

    public function recordPayment(Order $order, OrderPaymentData $data, ?User $actor = null): OrderPayment
    {
        return ($this->recordPayment)($order, $data, $actor);
    }

    public function refundPayment(Order $order, OrderPaymentData $data, ?User $actor = null): OrderPayment
    {
        return ($this->refundPayment)($order, $data, $actor);
    }

    /**
     * Undoes an entry that should never have been written, by writing another beside it.
     *
     * The reason is required rather than optional — see {@see ReverseOrderPayment}.
     */
    public function reversePayment(
        Order $order,
        OrderPayment $payment,
        string $reason,
        ?User $actor = null,
    ): OrderPayment {
        return ($this->reversePayment)($order, $payment, $reason, $actor);
    }

    /**
     * Closes what is left of an order's debt without any money moving — see
     * {@see WriteOffOrderBalance}. The reason is required, as it is for a reversal.
     */
    public function writeOffBalance(
        Order $order,
        string $amount,
        string $reason,
        ?User $actor = null,
    ): OrderPayment {
        return ($this->writeOffBalance)($order, $amount, $reason, $actor);
    }

    // ── manufacturing cost rates ────────────────────────────────────────────────────────

    /**
     * @return LengthAwarePaginator<int, ManufacturingCostRate>
     */
    public function paginateManufacturingCostRates(
        ManufacturingCostRateFilters $filters,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return ($this->manufacturingCostRateListQuery)($filters, $perPage);
    }

    public function createManufacturingCostRate(ManufacturingCostRateData $data): ManufacturingCostRate
    {
        return ($this->createManufacturingCostRate)($data);
    }

    public function updateManufacturingCostRate(
        ManufacturingCostRate $rate,
        ManufacturingCostRateData $data,
    ): ManufacturingCostRate {
        return ($this->updateManufacturingCostRate)($rate, $data);
    }

    /**
     * The ordinary way to retire a rate — it stops applying to orders entering ready from
     * this point on, and every entry it already produced keeps its own snapshotted value.
     */
    public function setManufacturingCostRateActive(ManufacturingCostRate $rate, bool $isActive): ManufacturingCostRate
    {
        $rate->update(['is_active' => $isActive]);

        return $rate;
    }

    /**
     * For the row that should never have existed — a typo, a rate entered against the wrong
     * product. Nothing points at a rate by foreign key (an applied rate is snapshotted onto its
     * `production_cost_entries` row, not referenced), so unlike a business field this needs no
     * in-use guard.
     */
    public function deleteManufacturingCostRate(ManufacturingCostRate $rate): void
    {
        $rate->delete();
    }

    // ── production ──────────────────────────────────────────────────────────────────────

    /**
     * Bags spoiled producing one line — see {@see RecordScrapLoss}. Only possible once the order
     * has reached ready.
     */
    public function recordScrapLoss(
        Order $order,
        OrderItem $item,
        string $quantity,
        string $notes,
        ?User $actor = null,
    ): ProductionCostEntry {
        if ($actor === null) {
            throw ScrapRequiresAnActor::make();
        }

        return ($this->recordScrapLoss)($order, $item, $quantity, $notes, (int) $actor->getKey());
    }

    /**
     * Everything needed to render one order in full.
     *
     * **The ledger is absent on purpose.** It is read through {@see payments()} behind its own
     * permission, so loading it here would be work done for every reader and shipped to the ones
     * not allowed to see it.
     */
    public function loadForDisplay(Order $order): Order
    {
        return $order->load([
            'customer', 'shop', 'city', 'region', 'creator',
            // Who said the customer had been told — a name on the order screen, and the only
            // reason this relation is ever loaded. The list does not: no card shows it.
            'readyMessenger',
            // The two names on the عربون: who claimed it was paid and who confirmed it arrived.
            // The order screen prints both beside the tick — they are the whole point of a rule
            // that says the two must differ — and, like the messenger above, no card shows them.
            'depositClaimer', 'depositConfirmer',
            // The product behind each line, with its photographs: the line draws the catalogue's
            // own card and opens it. Loaded here rather than per line — a four-line order would
            // otherwise be four queries, and `Model::shouldBeStrict()` would say so.
            'items', 'items.product.images', 'designs.customerDesign', 'transitions.user',
            // The shelf behind each line, for the unit its per-unit cost is quoted in — see
            // OrderItemResource. Two queries for the whole order, not two per line.
            'items.variant.stockItem',
        ]);
    }

    /**
     * One order's figures, flattened for another context to split.
     *
     * The only thing Investment ever asks Orders. Returns plain arrays rather than models, so
     * the dependency stays one-way and Orders can change its internals without a ripple.
     *
     * @return array<string, mixed>|null
     */
    /**
     * The lines of this order whose material the press bought off the shelf, and the draw behind
     * each — see {@see StockPurchaseAttributionQuery}.
     *
     * The door Investment comes through to settle سعر السادة. Empty for every order that drew on
     * nothing but the company's own stock, which is almost all of them.
     *
     * @return list<array{line_id: int, movement_id: int}>
     */
    public function stockPurchaseAttributionFor(int $orderId): array
    {
        return ($this->stockPurchaseAttribution)($orderId);
    }

    /**
     * Every line of this order, whatever it drew and whether it drew at all.
     *
     * The companion to `stockPurchaseAttributionFor()` above, and needed for the same settlement:
     * a restated line that stopped buying leaves that list, so Investment cannot ask it which
     * lines might still be holding money for a purchase they are no longer credited with. This
     * one names the whole order; which of them the ledger still owes is the ledger's own question.
     *
     * @return list<int>
     */
    public function lineIdsFor(int $orderId): array
    {
        return OrderItem::query()
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Every line of this order with what is still missing from it — the door Shortages reads
     * through.
     *
     * **`withTrashed()` on the order, and deliberately.** The row that context has most to say
     * about is often one a colleague archived a moment ago: a delete has to close the shortages
     * it left behind, and a scoped read would answer "no such order" in exactly the moment the
     * honest answer is "here it is, and it has gone". The same reason `DeleteOrder` locks
     * `withTrashed()`. Whether a *reader* may then see any of it is decided far from here, by
     * grants this method cannot know.
     *
     * **Lines that are not short are returned too.** «لا ينقص من هذا السطر شيء» is an answer the
     * reconciliation needs — it is how a shortage that has just been filled is told apart from a
     * line nobody mentioned — and the same reason `SetOrderShortages` writes every line rather
     * than merging a partial map.
     *
     * Empty for an order that does not exist, rather than throwing: a listener firing on a
     * deleted-then-purged row has nothing to do, and that is not a failure.
     *
     * @return list<OrderLineShortage>
     */
    public function shortageLinesFor(int $orderId): array
    {
        $order = Order::withTrashed()->whereKey($orderId)->first();

        if ($order === null) {
            return [];
        }

        return $order->items()
            ->orderBy('id')
            ->get()
            ->map(fn (OrderItem $item): OrderLineShortage => new OrderLineShortage(
                lineId: (int) $item->getKey(),
                orderId: $orderId,
                customerId: $order->customer_id === null ? null : (int) $order->customer_id,
                productId: (int) $item->product_id,
                productVariantId: (int) $item->product_variant_id,
                // The two halves of the snapshot joined the way every screen prints them, so the
                // shortage's own name needs no knowledge of how an order line is spelled.
                name: trim($item->product_name.' — '.$item->variant_label),
                unit: $item->pricing_unit->value,
                shortageQuantity: $item->shortage_quantity === null
                    ? null
                    : (string) $item->shortage_quantity,
            ))
            ->all();
    }

    /**
     * What this order is short of, line by line, as the shelves stand right now.
     *
     * A read that decides nothing: the deduction still refuses what it cannot cover, exactly as
     * before. This is what lets a screen fill in the «نواقص» form instead of leaving a foreman to
     * work four numbers out of one refusal. See {@see OrderStockShortfallQuery}.
     *
     * @return array{warehouse_id: int|null, available_scope: string, lines: list<array<string, mixed>>, is_short: bool}
     */
    public function stockShortfallFor(Order $order, ?int $warehouseId = null): array
    {
        return ($this->stockShortfall)($order, $warehouseId);
    }

    public function profitAttributionFor(int $orderId): ?array
    {
        return ($this->profitAttribution)($orderId);
    }

    /**
     * The same figures for a page of orders, in one read.
     *
     * A screen that lists the orders one deal sold into needs all of them at once; asking one at
     * a time is two queries per row for an answer two queries can give.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array<string, mixed>> keyed by order id
     */
    public function profitAttributionForMany(array $orderIds): array
    {
        return $this->profitAttribution->many($orderIds);
    }
}
