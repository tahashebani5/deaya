<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers\Client;

use App\Application\Api\V1\Requests\Client\Support\OpenTicketRequest;
use App\Application\Api\V1\Requests\Client\Support\PostTicketMessageRequest;
use App\Application\Api\V1\Resources\Client\ClientSupportTicketResource;
use App\Application\Controller;
use App\Domain\Customer\Models\Customer;
use App\Domain\Order\OrderService;
use App\Domain\Support\SupportService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Support
 *
 * How a customer reaches a person — a thread per question, and every message in it.
 *
 * **No customer id in any path**, as everywhere else in this API: every lookup is scoped to the
 * signed-in customer inside `SupportService`, so another customer's thread is a 404 rather than
 * a row somebody has to remember to check.
 *
 * **Opening a thread marks it read**, which is why there is no «mark as read» endpoint. Reading
 * a conversation is what makes it read, and a separate call would be one the app forgets on the
 * screen where it matters.
 */
class SupportController extends Controller
{
    use ResponseTrait;

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly SupportService $support,
        private readonly OrderService $orders,
    ) {}

    /**
     * My tickets
     *
     * Most recently active first. Pass `open=1` for the live ones, `open=0` for the closed.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $tickets = $this->support->paginateForCustomer(
            customerId: (int) $this->customer($request)->getKey(),
            openOnly: $request->has('open') ? $request->boolean('open') : null,
            perPage: $perPage,
        );

        return $this->successWithPagination(ClientSupportTicketResource::collection($tickets));
    }

    /**
     * Open a ticket
     *
     * A subject and a first message. Optionally about one of your own orders.
     */
    public function store(OpenTicketRequest $request): JsonResponse
    {
        $customer = $this->customer($request);

        $ticket = $this->support->open(
            customer: $customer,
            subject: $request->string('subject')->toString(),
            body: $request->string('body')->toString(),
            // **Resolved through the customer's own orders**, so an id belonging to somebody
            // else is a 404 rather than a ticket quietly attached to a stranger's order. A
            // validation rule could only have said the order exists.
            orderId: $request->filled('order_id')
                ? (int) $this->orders->findForCustomer(
                    (int) $customer->getKey(),
                    (int) $request->integer('order_id'),
                )->getKey()
                : null,
        );

        return $this->created(
            new ClientSupportTicketResource($ticket->load(['messages', 'order'])),
            'تم إرسال تذكرتك',
        );
    }

    /**
     * One ticket
     *
     * The whole thread, oldest message first. Opening it marks it read.
     */
    public function show(Request $request, int $ticket): JsonResponse
    {
        $owned = $this->support->findForCustomer((int) $this->customer($request)->getKey(), $ticket);

        $this->support->markRead($owned, staff: false);

        return $this->success(new ClientSupportTicketResource($owned->load(['messages', 'order'])));
    }

    /**
     * Reply
     *
     * **A reply to a closed ticket reopens it.** Writing into a thread you can still see means
     * it is not finished, and reopening is what you meant — see `PostTicketMessage`.
     */
    public function reply(PostTicketMessageRequest $request, int $ticket): JsonResponse
    {
        $customer = $this->customer($request);
        $owned = $this->support->findForCustomer((int) $customer->getKey(), $ticket);

        $this->support->replyAsCustomer($owned, $customer, $request->string('body')->toString());

        return $this->created(
            new ClientSupportTicketResource($owned->refresh()->load(['messages', 'order'])),
            'تم إرسال ردك',
        );
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
