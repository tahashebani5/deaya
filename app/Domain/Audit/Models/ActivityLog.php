<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * One entry in the audit trail.
 *
 * Extends the package's model so the Audit context owns the queries it needs, and so the rest
 * of the application never imports a vendor class to read its own history. `activitylog.php`
 * points `activity_model` here, which is what makes every logged event arrive as this class.
 *
 * **Append-only.** Nothing in the application updates or deletes a row: an audit trail that can
 * be edited is not one. There is deliberately no `Auditable` here either — logging the log
 * would recurse.
 *
 * @property-read Model|null $causer
 * @property-read Model|null $subject
 */
class ActivityLog extends Activity
{
    /**
     * Narrows the trail to one record's story: itself and anything it owns.
     *
     * Grouped by morph alias so the whole set costs one `where subject_type = ? and subject_id
     * in (?)` per kind, which the (subject_type, subject_id) index serves directly. The outer
     * closure keeps the OR set from escaping and swallowing the event and date filters.
     *
     * @param  Builder<$this>  $query
     * @param  array<string, list<int|string>>  $subjects  morph alias => ids
     * @return Builder<$this>
     */
    public function scopeForSubjects(Builder $query, array $subjects): Builder
    {
        return $query->where(function (Builder $query) use ($subjects): void {
            foreach ($subjects as $type => $ids) {
                if ($ids === []) {
                    continue;
                }

                $query->orWhere(function (Builder $query) use ($type, $ids): void {
                    $query->where('subject_type', $type)->whereIn('subject_id', $ids);
                });
            }

            // An empty set must match nothing. Without this an all-empty $subjects would leave
            // the closure with no conditions at all, and the trail of one record would quietly
            // become the trail of everything.
            $query->orWhereRaw('1 = 0');
        });
    }
}
