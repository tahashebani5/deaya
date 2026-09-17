<?php

declare(strict_types=1);

namespace App\Domain\Audit\Concerns;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Audit\Models\ActivityLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The audit trail, in one trait.
 *
 * Every model in `app/Domain/` carries this and {@see SoftDeletes}. That pairing is a project
 * rule rather than a preference — see §12 of RULES.md — and `ModelConventionsTest` fails the
 * build for a model that is missing either.
 *
 * **The default logs everything and needs no per-model configuration.** Deliberately: a policy
 * you have to remember to write is a policy that gets forgotten on the model where it mattered.
 * Adding a column to a table adds it to the history automatically, and adding a *model* only
 * ever costs a `use` line. A model with a genuine reason to log less overrides
 * {@see getActivitylogOptions()} and says why.
 *
 * Secrets never reach the log: `activitylog.default_except_attributes` strips `password` and
 * `remember_token` for every model, so no individual model can forget to.
 */
trait Auditable
{
    use LogsActivity;

    /**
     * What gets recorded, and how.
     *
     * `logOnlyDirty` keeps an update's entry down to the fields that actually moved, so a
     * history screen shows "الاسم: قديم ← جديد" rather than every column re-stated.
     * `dontLogEmptyChanges` then drops the entry entirely when nothing moved — a PUT that
     * re-sends identical values is not an event.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            // Groups the row under the kind of record it describes: 'product', 'city' …
            ->useLogName($this->getMorphClass())
            ->logExcept($this->attributesNeverWorthLogging())
            // A row whose only change is the timestamp Eloquent maintains is not a change.
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->setDescriptionForEvent(fn (string $event): string => $this->auditDescription($event));
    }

    /**
     * Noise, not history.
     *
     * The id never changes, the timestamps are already on the log row itself, and `deleted_at`
     * would restate what the `deleted` / `restored` event says more clearly.
     *
     * @return list<string>
     */
    protected function attributesNeverWorthLogging(): array
    {
        return ['id', 'created_at', 'updated_at', 'deleted_at'];
    }

    /**
     * The Arabic sentence stored on the row — "تم إنشاء منتج".
     *
     * Stored rather than composed at read time so history stays readable after the enum that
     * produced it is reworded: the row says what was said when it happened.
     */
    protected function auditDescription(string $event): string
    {
        $subject = AuditSubject::tryFrom($this->getMorphClass())?->label();

        return AuditEvent::tryFrom($event)?->sentence($subject) ?? $event;
    }

    /**
     * This record's own history.
     *
     * @return MorphMany<ActivityLog, $this>
     */
    public function activities(): MorphMany
    {
        /** @var MorphMany<ActivityLog, $this> */
        return $this->activitiesAsSubject();
    }

    /**
     * Every subject whose history is part of *this* record's story, as morph alias => ids.
     *
     * A plain record answers with itself. A record that owns others — a product owning its
     * sizes, prices and photos — overrides this to include them, because "what happened to this
     * product" plainly includes the day its price changed, and a client should not have to know
     * that a price lives on a different table to ask.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [$this->getMorphClass() => [$this->getKey()]];
    }
}
