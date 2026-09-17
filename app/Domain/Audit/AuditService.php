<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Audit\Queries\ActivityFilters;
use App\Domain\Audit\Queries\ActivityListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The Audit module's public front door.
 *
 * Everything else asks here — no controller queries {@see ActivityLog} itself. The seam earns
 * its keep the day the trail moves somewhere a relational query cannot reach it: a separate
 * database once it outgrows this one, or an append-only store. Only this class would change.
 *
 * **There is no `record()` method, and that is the design.** Nothing calls the audit module to
 * write; models log themselves through {@see Concerns\Auditable}. A trail you have to remember
 * to write to is a trail with holes in exactly the places someone was in a hurry.
 *
 * Dependencies point *into* this module and never out of it. Audit knows the
 * {@see HasAuditTrail} interface; it does not know that a Product exists.
 */
class AuditService
{
    public function __construct(private readonly ActivityListQuery $listQuery) {}

    /**
     * One record's history — its own entries plus those of anything it owns.
     *
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginateFor(HasAuditTrail $record, ActivityFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->listQuery)($record->auditTrailSubjects(), $filters, $perPage);
    }

    /**
     * How many entries of each kind one record's history holds.
     *
     * Sent beside the page so a history screen's filter chips can say what tapping each one
     * would give. Counting on the client would be a lie the moment the trail is longer than a
     * page — which, for a customer of any age, it always is.
     *
     * @return array<string, int>
     */
    public function eventCountsFor(HasAuditTrail $record, ActivityFilters $filters): array
    {
        return $this->listQuery->countsByEvent($record->auditTrailSubjects(), $filters);
    }

    /**
     * Everything that has happened, newest first.
     *
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginate(ActivityFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->listQuery)(null, $filters, $perPage);
    }
}
