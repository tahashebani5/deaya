<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Client\Support\PostTicketMessageRequest;
use App\Application\Api\V1\Resources\SupportTicketResource;
use App\Application\Controller;
use App\Domain\Identity\Models\User;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\SupportService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Support tickets
 *
 * The desk: what customers are asking, whose it is, and what was said.
 *
 * **A view/manage pair rather than one permission**, unlike billboards. Reading the queue is
 * something a whole shift may need — «هل يسأل أحد عن الطلبية ١٢٢٠؟» — while answering, assigning
 * and closing is a job, and the business composes the two however it likes.
 *
 * No `store`: a ticket is a customer starting a conversation, and the shop opening one on
 * somebody's behalf would be a thread the customer never asked for.
 */
class SupportTicketController extends Controller
{
    use ResponseTrait;

    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    public function __construct(private readonly SupportService $support) {}

    /**
     * The queue
     *
     * Most recently active first. Filter with `status` and `assigned_to`.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', self::DEFAULT_PER_PAGE), 1), self::MAX_PER_PAGE);

        $tickets = $this->support->paginateForStaff(
            status: TicketStatus::tryFrom((string) $request->query('status', '')),
            assignedTo: ($id = (int) $request->query('assigned_to', 0)) > 0 ? $id : null,
            perPage: $perPage,
        );

        return $this->successWithPagination(SupportTicketResource::collection($tickets));
    }

    /**
     * One ticket
     *
     * The whole thread. Opening it marks the desk's side read.
     */
    public function show(int $ticket): JsonResponse
    {
        $found = $this->support->find($ticket);

        $this->support->markRead($found, staff: true);

        return $this->success(new SupportTicketResource($found->refresh()->load(['messages.author', 'customer', 'order', 'assignee'])));
    }

    /**
     * Reply
     *
     * Puts the ticket on «قيد المعالجة» — the shop has answered, so it is on a desk.
     *
     * **Refused on a closed ticket**, unlike a customer's reply, which reopens it. Adding to a
     * conversation the shop decided was over is a decision worth making deliberately: reopen it
     * first.
     */
    public function reply(PostTicketMessageRequest $request, int $ticket): JsonResponse
    {
        $found = $this->support->find($ticket);
        $staff = $request->user();

        assert($staff instanceof User);

        $this->support->replyAsStaff($found, $staff, $request->string('body')->toString());

        return $this->created(
            new SupportTicketResource($found->refresh()->load(['messages.author', 'customer', 'order', 'assignee'])),
            'تم إرسال الرد',
        );
    }

    /**
     * Assign a ticket
     *
     * Send `assigned_to: null` to take it off a desk and back into the unassigned queue.
     */
    public function assign(Request $request, int $ticket): JsonResponse
    {
        $validated = $request->validate([
            'assigned_to' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ]);

        $updated = $this->support->assign(
            $this->support->find($ticket),
            $validated['assigned_to'] === null ? null : (int) $validated['assigned_to'],
        );

        return $this->success(
            new SupportTicketResource($updated->load(['customer', 'order', 'assignee'])),
            'تم إسناد التذكرة',
        );
    }

    /**
     * Close a ticket
     *
     * Idempotent — two people pressing the same button is not a failure, and the second must not
     * overwrite the first one's name.
     */
    public function close(Request $request, int $ticket): JsonResponse
    {
        $staff = $request->user();

        assert($staff instanceof User);

        $closed = $this->support->close($this->support->find($ticket), $staff);

        return $this->success(
            new SupportTicketResource($closed->load(['customer', 'order', 'assignee'])),
            'تم إغلاق التذكرة',
        );
    }
}
