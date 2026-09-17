<?php

declare(strict_types=1);

namespace App\Domain\Audit\Queries;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\ActivityLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reading the audit trail — either one record's history, or the whole feed.
 *
 * One class for both, because they differ by a single `where`: the same filters, ordering and
 * eager loading apply to a product's history and to everything that happened yesterday.
 */
final class ActivityListQuery
{
    /**
     * @param  array<string, list<int|string>>|null  $subjects  morph alias => ids; null for the
     *                                                          unrestricted feed
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function __invoke(?array $subjects, ActivityFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->baseQuery($subjects, $filters)
            // The causer is rendered on every row. Strict mode turns a forgotten eager load
            // into an exception rather than one query per entry, but only where a causer
            // exists — plenty of rows are the system's own work and have none.
            ->with('causer')
            ->when($filters->event !== null, fn (Builder $q) => $q->where('event', $filters->event?->value))
            // Newest first, and by id rather than by created_at: several entries written inside
            // one transaction share a timestamp to the second, and ordering by it alone would
            // shuffle them between pages. The id is the only total order there is.
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * How many entries of each kind this trail holds — `['created' => 4, 'updated' => 2, …]`.
     *
     * **Deliberately ignores `$filters->event`.** These numbers sit on the filter chips
     * themselves, so they have to say how many the user *would* get by tapping each one. Applied
     * to itself, every chip but the active one would read zero, which is the opposite of what a
     * filter control is for.
     *
     * Every event is present, including the ones with nothing behind them: a chip that appears
     * and disappears as the data changes is a control whose position cannot be learnt.
     *
     * @param  array<string, list<int|string>>|null  $subjects
     * @return array<string, int>
     */
    public function countsByEvent(?array $subjects, ActivityFilters $filters): array
    {
        /** @var array<string, int> $found */
        $found = $this->baseQuery($subjects, $filters)
            ->getQuery()
            ->select('event')
            ->selectRaw('count(*) as total')
            ->groupBy('event')
            ->pluck('total', 'event')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $counts = [];

        foreach (AuditEvent::cases() as $event) {
            $counts[$event->value] = $found[$event->value] ?? 0;
        }

        return $counts;
    }

    /**
     * Everything both readers agree on: which records, by whom, and when.
     *
     * The event filter is *not* here — see {@see countsByEvent()} for why the two differ on
     * exactly that one clause.
     *
     * @param  array<string, list<int|string>>|null  $subjects
     * @return Builder<ActivityLog>
     */
    private function baseQuery(?array $subjects, ActivityFilters $filters): Builder
    {
        return ActivityLog::query()
            ->when($subjects !== null, fn (Builder $q) => $q->forSubjects($subjects ?? []))
            ->when($filters->subjectType !== null, fn (Builder $q) => $q->where('subject_type', $filters->subjectType?->value))
            ->when($filters->causerId !== null, fn (Builder $q) => $q->where('causer_id', $filters->causerId))
            ->when($filters->from !== null, fn (Builder $q) => $q->where('created_at', '>=', $filters->from))
            ->when($filters->to !== null, fn (Builder $q) => $q->where('created_at', '<=', $filters->to));
    }
}
