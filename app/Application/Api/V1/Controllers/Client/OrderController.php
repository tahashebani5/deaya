<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers\Client;

use App\Application\Api\V1\Requests\Client\Order\RequestOrderRequest;
use App\Application\Api\V1\Resources\Client\ClientOrderDetailResource;
use App\Application\Api\V1\Resources\Client\ClientOrderResource;
use App\Application\Controller;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\Actions\RequestOrder;
use App\Domain\Order\DTOs\OrderData;
use App\Domain\Order\DTOs\OrderItemData;
use App\Domain\Order\Enums\CustomerOrderStage;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\OrderService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * My orders
 *
 * What the customer has ordered, and where each one has got to.
 *
 * **No customer id in either path.** Both endpoints resolve the customer from the token and
 * query through their own relation, so another customer's order is a 404 by construction rather
 * than by a check somebody has to remember — the same guarantee `scoped()` gives the staff
 * routes, arrived at the only way it can be when there is no parent segment.
 *
 * **And the workshop's vocabulary never leaves.** Nineteen statuses reach the app as eight
 * stages — see {@see CustomerOrderStage}. «قيد التصنيع» would tell a customer we outsourced
 * their job and «نواقص» that our shelf was empty; both are true and neither is theirs.
 */
class OrderController extends Controller
{
    use ResponseTrait;

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly OrderService $orders,
        private readonly RequestOrder $requestOrder,
    ) {}

    /**
     * My orders
     *
     * Newest first. Pass `open=1` for the ones still being worked on — everything that has not
     * been delivered, written off, or finished and set aside for collection — or `stage=` with
     * one of the eight stage values for a single one of them. See {@see openStatuses()} for why
     * «جاهزة» is outside `open=1` although a ready order is still `is_open`.
     *
     * **`stage` is the app's vocabulary, translated here.** A customer's screen offers «جاهزة»
     * and «مكتملة»; the Order context knows «استلام مكتب» and «تم التسوية» and must not be asked
     * about either by a phone. {@see CustomerOrderStage::statuses()} does the translation, and
     * it derives it from the same `match` that produces `stage_label`, so the filter and the
     * badge can never disagree about what «جاهزة» means.
     *
     * An unknown value is **no filter at all**, deliberately: a stage this server has not heard
     * of is a newer app talking to an older server, and showing that customer their whole list
     * is better than showing them an empty one and letting them conclude their orders are gone.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        // **Translated from stages to statuses here**, so the app filters in its own vocabulary
        // and never has to learn the workshop's. Asking for the open ones is a question about
        // where an order has got to, not about which of nineteen rows it is standing on — and
        // the Order context, which knows nothing of stages, is handed the answer rather than the
        // question.
        $orders = $this->orders->paginateForCustomer(
            customerId: (int) $this->customer($request)->getKey(),
            perPage: $perPage,
            onlyStatuses: $this->statusesFor($request),
        );

        return $this->successWithPagination(ClientOrderResource::collection($orders));
    }

    /**
     * Place an order
     *
     * **The order is born «بانتظار المراجعة», not «جديدة».** «جديدة» means a person checked it:
     * a clerk typing an order down has spoken to the customer first, and from «جديدة» the next
     * move takes goods off the shelf. Nothing checked this one, so it waits for somebody to read
     * it — see {@see RequestOrder}.
     *
     * Every line is priced by the server from the same code the quote endpoint uses, so the
     * total here is the total the app showed. A client cannot send a price, a discount, or a
     * vendor; see {@see RequestOrderRequest} for the whole list of what is refused by absence.
     */
    public function store(RequestOrderRequest $request): JsonResponse
    {
        $customer = $this->customer($request);
        $validated = $request->validated();

        $order = ($this->requestOrder)(new OrderData(
            // **From the token, never from the body.** A customer who could name a customer id
            // could order in somebody else's name and then read the order back.
            customerId: (int) $customer->getKey(),
            cityId: (int) $validated['city_id'],
            customerShopId: isset($validated['customer_shop_id']) ? (int) $validated['customer_shop_id'] : null,
            regionId: isset($validated['region_id']) ? (int) $validated['region_id'] : null,
            recipientName: $validated['recipient_name'] ?? null,
            recipientPhone: $validated['recipient_phone'] ?? null,
            addressDetails: $validated['address_details'] ?? null,
            // What the customer wrote lands in the order's note, prefixed so whoever reviews it
            // can see at a glance that these are the customer's words and not a colleague's.
            notes: isset($validated['customer_note'])
                ? 'ملاحظة العميل: '.$validated['customer_note']
                : null,
            // **`allowsQuoteLater` is set here and nowhere else.** A product priced «حسب الطلب»
            // is never sent a price by this API — `RequestOrderRequest` refuses `unit_price` for
            // the reason written at the top of it — so the app *cannot* name one, and refusing
            // the order for want of it made the whole quote-on-request half of the catalogue
            // unorderable from a phone. The line arrives unpriced and the shop quotes it on the
            // move that accepts the request; until then no total is shown to anybody.
            //
            // The staff endpoint passes nothing, so it keeps the old rule: a clerk leaving the
            // price box empty is still refused.
            items: array_map(
                fn (array $item, int $index) => OrderItemData::fromArray(
                    $item,
                    $index,
                    allowsQuoteLater: true,
                ),
                $validated['items'],
                array_keys($validated['items']),
            ),
            designIds: array_map('intval', $validated['design_ids'] ?? []),
        ));

        return $this->created(
            new ClientOrderDetailResource($order->load(['items', 'transitions'])),
            'تم إرسال طلبك، وسنراجعه ونؤكده قريباً',
        );
    }

    /**
     * One order
     *
     * With its lines, what is owed on it, and the stages it has passed through.
     */
    public function show(Request $request, int $order): JsonResponse
    {
        $owned = $this->orders->findForCustomer(
            (int) $this->customer($request)->getKey(),
            $order,
        );

        return $this->success(new ClientOrderDetailResource($owned));
    }

    /**
     * Every workshop status that reads as a stage the order is still being *worked on* in.
     *
     * Derived from {@see CustomerOrderStage} rather than listed, so a status added to the
     * business joins this filter by being given a stage and by nothing else. A hand-written list
     * here would be the second place the mapping lives, and the second place is the one that
     * drifts.
     *
     * **«جاهزة» is excluded, and that is not the same as `isOpen()`.** A ready order is still
     * open — nobody has taken delivery of it and nobody wrote it off, and `is_open` on the
     * resource says so. But «قيد التنفيذ» sits beside its own «جاهزة» chip on the customer's
     * screen, and a chip that contains its neighbour is two chips answering one question: the
     * same order under both, and no way to see the ones still being made on their own. The
     * customer's reading is the one that governs here — «قيد التنفيذ» means we are still working
     * on it, and a bag sitting on the counter waiting for you is not that.
     *
     * The exclusion lives here, in the client controller, rather than in `isOpen()`: `is_open`
     * is asked by other things — the badge, `PagedCubit.belongs` — and narrowing it would move a
     * chip's problem into the vocabulary the whole app shares.
     *
     * @return list<string>
     */
    private static function openStatuses(): array
    {
        return array_values(array_map(
            fn (OrderStatus $status) => $status->value,
            array_filter(OrderStatus::cases(), function (OrderStatus $status): bool {
                $stage = CustomerOrderStage::forStatus($status);

                return $stage->isOpen() && $stage !== CustomerOrderStage::Ready;
            }),
        ));
    }

    /**
     * The statuses this request asks for, or null for all of them.
     *
     * **`stage` wins over `open`, because it is the narrower question.** Sending both is a
     * client asking for «جاهزة» *and* «قيد التنفيذ»; every stage that is open is already inside
     * one of those two answers, so honouring the narrower one is the only reading that is not
     * arbitrary.
     *
     * @return list<string>|null
     */
    private function statusesFor(Request $request): ?array
    {
        $stage = CustomerOrderStage::tryFrom($request->string('stage')->toString());

        if ($stage !== null) {
            return array_values(array_map(
                fn (OrderStatus $status): string => $status->value,
                $stage->statuses(),
            ));
        }

        return $request->boolean('open') ? self::openStatuses() : null;
    }

    /**
     * The signed-in customer, narrowed from the guard's contract to the model. The route group's
     * `auth:customer` middleware is what makes the assertion true.
     */
    private function customer(Request $request): Customer
    {
        $customer = $request->user();

        assert($customer instanceof Customer);

        return $customer;
    }
}
