<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Shortage\AssignShortageRequest;
use App\Application\Api\V1\Requests\Shortage\ChangeShortageStatusRequest;
use App\Application\Api\V1\Requests\Shortage\RecordShortageSupplyRequest;
use App\Application\Api\V1\Requests\Shortage\ReverseShortageSupplyRequest;
use App\Application\Api\V1\Requests\Shortage\StoreShortageRequest;
use App\Application\Api\V1\Requests\Shortage\UpdateShortageRequest;
use App\Application\Api\V1\Resources\ShortageResource;
use App\Application\Api\V1\Resources\ShortageSupplyResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Shortage\DTOs\ShortageData;
use App\Domain\Shortage\DTOs\ShortageSupplyData;
use App\Domain\Shortage\Models\Shortage;
use App\Domain\Shortage\Models\ShortageSupply;
use App\Domain\Shortage\Queries\ShortageFilters;
use App\Domain\Shortage\ShortageService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shortages
 *
 * What the shop is short of and the chase to get it — written down by hand, or generated from an
 * order line entering «نواقص». `new → searching → unavailable`, with `completed` written by the
 * arithmetic when the last of the quantity is supplied and never chosen by anybody; see
 * `ShortageStatus`.
 *
 * Reading needs `shortages.view`; writing one and moving it needs `shortages.manage`. Assigning
 * is `shortages.assign` — routing work is a different job from doing it — and the two money verbs
 * split again into `shortages.supplies.record` and `.reverse`, the same three-way split
 * `orders.payments.*` makes.
 *
 * **A shortage about an archived order is readable only with `orders.archive.view` on top**, and
 * that is not a nicety: these rows carry the order's code and the customer's name, so without it
 * this section would answer for every order ever deleted — see
 * `ArchivedOrderShortagesNeedTheArchiveGrant` and Docs/shortages/SHORTAGES-DESIGN.md §٥.
 *
 * No destroy route for a supply. The ledger is append-only and a mistake is corrected by a
 * reversal that names it — see {@see reverseSupply()}, and `order_payments` for the precedent.
 */
class ShortageController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly ShortageService $shortages) {}

    /**
     * List shortages
     *
     * Newest first. Filter with `status` (repeatable), `assigned_to`, `product_id`, `source`,
     * `order_id` and `customer_id`, and narrow with `search` — the shortage's own name, its code,
     * or the order's code.
     *
     * `assigned_to=me` is the employee's own queue and `assigned_to=none` is what nobody has
     * picked up yet. Both are words rather than ids because neither is one: «me» is only known
     * here, and «none» is a null the query string cannot otherwise carry.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filtersFrom($request);
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            ShortageResource::collection($this->shortages->paginate($filters, $perPage)),
        );
    }

    /**
     * Shortages by status
     *
     * How many stand in each status, under the same filters the list takes. What the chip row
     * «جديد ١٢ | جاري البحث ٧ | غير متوفر ٣ | مكتمل ٢٥» is drawn from — one call rather than one
     * per status.
     *
     * Every status is present, zeros included: a missing key would leave the caller choosing
     * between a blank and a zero, and the two mean different things.
     *
     * `status` is accepted and ignored, so a screen may hand its whole filter over without
     * stripping the one field that would make every chip but one read zero.
     *
     * **Wrapped in `counts` with a `total` beside it**, the shape `/orders/summary` and
     * `/purchase-orders/summary` already answer in. A flat map would have been one key shorter
     * and a third thing for the app to special-case — and the total is read rather than summed
     * on the client, so a status added after a build shipped is still inside the number.
     */
    public function statusCounts(Request $request): JsonResponse
    {
        $counts = $this->shortages->statusCounts($this->filtersFrom($request));

        return $this->success(['counts' => $counts, 'total' => array_sum($counts)]);
    }

    /**
     * Create a shortage
     *
     * Manual entry. The product link is optional — what gets written down by hand is often
     * something the catalogue has never heard of — but the name and the quantity are not.
     *
     * Shortages that come from an order are not created here: they are generated when an order
     * enters «نواقص», against the line that is short.
     */
    public function store(StoreShortageRequest $request): JsonResponse
    {
        $shortage = $this->shortages->create(ShortageData::fromArray($request->validated()), $request->user());

        return $this->created(
            new ShortageResource($this->shortages->loadForDisplay($shortage)),
            'تم تسجيل النقص',
        );
    }

    /**
     * Show a shortage
     *
     * With its whole supply ledger — §٩'s table, reversals included, so a total that looks wrong
     * can be read rather than guessed at.
     */
    public function show(Shortage $shortage): JsonResponse
    {
        return $this->success(new ShortageResource($this->shortages->loadForDisplay($shortage)));
    }

    /**
     * Update a shortage
     *
     * Manual shortages only. What an order-born one is short *of* comes from its line and is
     * corrected on the order screen, where the invoice changes with it — 422 here.
     */
    public function update(UpdateShortageRequest $request, Shortage $shortage): JsonResponse
    {
        $updated = $this->shortages->update($shortage, ShortageData::fromArray($request->validated()));

        return $this->success(
            new ShortageResource($this->shortages->loadForDisplay($updated)),
            'تم تحديث النقص',
        );
    }

    /**
     * Change a shortage's status
     *
     * `searching` and `unavailable` are the only targets a person may ask for. «مكتمل» is written
     * by the arithmetic when the remaining quantity reaches zero, and «جديد» is where a shortage
     * starts and nothing leads back to it.
     *
     * «غير متوفر» is not the end: recording a supply against one puts it back in the chase.
     */
    public function changeStatus(ChangeShortageStatusRequest $request, Shortage $shortage): JsonResponse
    {
        $updated = $this->shortages->changeStatus($shortage, $request->status());

        return $this->success(
            new ShortageResource($this->shortages->loadForDisplay($updated)),
            'تم تحديث حالة النقص',
        );
    }

    /**
     * Assign a shortage
     *
     * Send `assigned_to_user_id: null` to take it out of everybody's queue — the field is
     * required to be present either way, so an omission is a mistake rather than an instruction.
     *
     * A shortage still reading «جديد» moves to «جاري البحث» on the way: «لم تبدأ متابعته بعد»
     * stops being true the moment it lands in somebody's queue.
     */
    public function assign(AssignShortageRequest $request, Shortage $shortage): JsonResponse
    {
        $assigneeId = $request->validated('assigned_to_user_id');

        $updated = $this->shortages->assign(
            $shortage,
            $assigneeId === null ? null : User::query()->findOrFail($assigneeId),
            $request->user(),
        );

        return $this->success(
            new ShortageResource($this->shortages->loadForDisplay($updated)),
            $assigneeId === null ? 'تم إلغاء إسناد النقص' : 'تم إسناد النقص',
        );
    }

    /**
     * Record a supply
     *
     * «تسجيل توفير» — the quantity that came back, what was paid for it, and how. All three are
     * required, and the quantity may not exceed what is left.
     *
     * Partial is ordinary: twenty kilos of a thirty-kilo shortage leaves it open with ten
     * remaining. The shortage closes itself, and only closes itself, when the remainder reaches
     * zero.
     *
     * On a shortage that came from an order, this also puts the goods back on the customer's
     * invoice — the quantity is subtracted from the line's shortage, which is what
     * `OrderItem::billableQuantity()` reads. An order whose lines have already closed, or that
     * has been archived, keeps its invoice as it stands; the purchase is recorded either way.
     */
    public function recordSupply(RecordShortageSupplyRequest $request, Shortage $shortage): JsonResponse
    {
        $supply = $this->shortages->recordSupply(
            $shortage,
            ShortageSupplyData::fromArray($request->validated()),
            $request->user(),
        );

        return $this->created(
            new ShortageSupplyResource($supply->load('recorder')),
            'تم تسجيل عملية التوفير',
        );
    }

    /**
     * Reverse a supply
     *
     * For an entry made in error. Nothing is edited and nothing is removed: a second row is
     * written that names the first and carries the same figures, and the totals are restated from
     * what is left standing — which reopens a shortage this un-completes.
     *
     * The reason is required. «عُكست» with no sentence beside it answers nothing six months later.
     *
     * This does **not** take the goods back off the customer's invoice: a reversal here means the
     * entry was wrong, not that sacks were returned. An order whose goods really did not arrive
     * is corrected on the order screen.
     */
    public function reverseSupply(
        ReverseShortageSupplyRequest $request,
        Shortage $shortage,
        ShortageSupply $supply,
    ): JsonResponse {
        $reversal = $this->shortages->reverseSupply(
            $shortage,
            $supply,
            (string) $request->validated('reason'),
            $request->user(),
        );

        return $this->created(
            new ShortageSupplyResource($reversal->load('recorder')),
            'تم عكس عملية التوفير',
        );
    }

    /**
     * A shortage's history
     *
     * Every change made to it and who made it — including the ones the reconciliation made on its
     * own when the order behind it moved.
     */
    public function logs(ActivityLogFilterRequest $request, Shortage $shortage, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $shortage, $audit);
    }

    /**
     * The filters, with the two things only this layer knows folded in.
     *
     * `assigned_to=me` needs the signed-in user, and whether archived orders are in scope needs
     * their grants. Neither belongs in {@see ShortageFilters}, which is handed around the domain
     * where there is no request to ask.
     */
    private function filtersFrom(Request $request): ShortageFilters
    {
        $query = $request->only([
            'status', 'assigned_to', 'product_id', 'source', 'order_id', 'customer_id', 'search',
        ]);

        if (($query['assigned_to'] ?? null) === 'me') {
            $query['assigned_to'] = $request->user()?->getKey();
        }

        return ShortageFilters::fromArray(
            $query,
            includeArchivedOrders: $request->user()?->can(PermissionName::ViewOrderArchive->value) === true,
        );
    }
}
