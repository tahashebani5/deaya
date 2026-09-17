<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Resources\ActivityLogResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * Activity log
 *
 * Everything that has happened, across every record — the feed behind an "آخر النشاطات" screen
 * and the place to answer "what did this employee change last week".
 *
 * **The per-record endpoints are the ones to reach for first.** `GET /products/{product}/logs`,
 * `GET /customers/{customer}/logs`, `GET /cities/{city}/logs`, `GET /users/{user}/logs` and
 * `GET /roles/{role}/logs` each return their record's own story, including the rows it owns —
 * a product's history covers its sizes, prices and photos. This one is the unfiltered stream.
 *
 * Entries are written automatically whenever a record is created, changed, deleted or restored.
 * Nothing writes to this log by hand, so nothing can forget to.
 */
class ActivityLogController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * List everything that happened
     *
     * Newest first. Narrow it with `subject_type` (`product`, `customer`, `city` …), `event`
     * (`created`, `updated`, `deleted`, `restored`), `causer_id`, and the inclusive date range
     * `from` / `to`.
     */
    public function index(ActivityLogFilterRequest $request): JsonResponse
    {
        return $this->successWithPagination(
            ActivityLogResource::collection(
                $this->audit->paginate($request->filters(), $request->perPage()),
            ),
        );
    }
}
