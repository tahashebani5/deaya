<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers\Concerns;

use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Resources\ActivityLogResource;
use App\Domain\Audit\AuditService;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * The body of every `GET /{resource}/{id}/logs` endpoint.
 *
 * The history of a product and the history of a city differ only in which record is asked
 * about, so there is one implementation and each controller supplies the record.
 *
 * Keeping the *route* per resource is the part that matters. `/products/{product}/logs`
 * resolves and authorizes through the product it belongs to, so model binding, the `can:`
 * middleware on that resource and its 404 behaviour all apply unchanged — none of which a
 * single `/logs?subject_type=product&subject_id=7` endpoint would inherit, and all of which it
 * would eventually get wrong for one resource.
 */
trait ReadsAuditTrail
{
    // Pulled in here rather than assumed: the controllers using this already have it, and PHP
    // flattens the same trait reached twice without complaint, so the dependency is stated
    // instead of being a runtime surprise for the next controller that adds a history endpoint.
    use ResponseTrait;

    protected function auditTrailResponse(
        ActivityLogFilterRequest $request,
        HasAuditTrail $record,
        AuditService $audit,
    ): JsonResponse {
        $filters = $request->filters();
        $entries = $audit->paginateFor($record, $filters, $request->perPage());

        return $this->successWithPagination(
            ActivityLogResource::collection($entries),
            extraMeta: [
                // What tapping each filter chip would give. In `meta` and not derived from
                // `data`, because a client counting the rows in front of it is counting one
                // page — and the count under an «إنشاء» chip has to mean the whole trail.
                'event_counts' => $audit->eventCountsFor($record, $filters),
            ],
        );
    }
}
