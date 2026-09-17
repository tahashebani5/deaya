<?php

declare(strict_types=1);

namespace App\Domain\Audit\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Keeps the cascade that soft deletes take away.
 *
 * `regions.city_id` is declared `cascadeOnDelete`, and before soft deletes that was the whole
 * story: remove a city and PostgreSQL removed its neighbourhoods. Nothing is removed any more —
 * `deleted_at` is set — so the foreign key never fires. Left alone, a deleted city's regions
 * stay alive, and the first symptom is a checkout offering neighbourhoods of a city that no
 * longer exists.
 *
 * The obvious fix is to cascade inside the action that deletes. That was the first attempt, and
 * it is wrong: it holds only for callers that go through that action. `$city->delete()` from a
 * console command, a future endpoint or a test still orphans the children, and nothing says so
 * until something breaks far away.
 *
 * So the cascade lives on the model, where it already lived — it has simply moved from the
 * foreign key to the event. Every path that deletes a parent takes its children, exactly as
 * before.
 *
 * **One model at a time, never a bulk update.** A mass delete on a relation fires no model
 * events: the children would vanish from the API leaving nothing in the audit trail to say what
 * happened to them, which is the one gap this whole change exists to close. The sets are small
 * — a city's regions, a variant's price tiers — and a handful of statements is a cheap price
 * for a history without holes.
 *
 * Atomicity is the caller's job: the action that deletes wraps parent and children in one
 * transaction. This trait guarantees *what* goes, not that it goes all at once.
 */
trait CascadesSoftDeletes
{
    protected static function bootCascadesSoftDeletes(): void
    {
        static::deleting(function (Model $model): void {
            // A force delete is a real DELETE, so the foreign key's own cascade still runs and
            // doing it again here would be wasted work on rows already gone.
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return;
            }

            foreach ($model->softDeleteCascades() as $relation) {
                $model->{$relation}()->each(fn (Model $child) => $child->delete());
            }
        });
    }

    /**
     * The relations that have no life outside this record, by name.
     *
     * **Only records that are really deleted carry this trait.** A city is deletable and a
     * variant is dropped from a product's price list, so both take their children with them.
     * Customers and products are deactivated and never deleted — their shops, sizes and photos
     * stay attached, so that if one is ever removed and brought back it comes back whole. There
     * is no risk in leaving them: every route to a child goes through its parent, so a child of
     * a deleted parent is already unreachable.
     *
     * Deliberately not derived from the schema's foreign keys either. `cascadeOnDelete`
     * describes what the database does with a real delete; this describes what the *business*
     * means by owning something, and the two are allowed to differ.
     *
     * Restoring does not cascade. Bringing a city back leaves its regions deleted, because
     * "undo that one delete" and "undo everything that ever happened to this city" are
     * different requests and only the first one has been asked for.
     *
     * **The restore endpoint has landed, and it settles this the same way.**
     * `POST orders/{order}/restore` is the first one — see
     * `App\Domain\Order\Actions\RestoreOrder` and §٥ of
     * Docs/orders/ORDER-DELETE-AND-ARCHIVE.md — and the question turned out not to need
     * deciding, because `Order` never joins the list above and the reason is mechanical rather
     * than a matter of taste. `Order::progress()` falls through to `furthestMainLineStep()` for
     * every status outside the main line, and that method's first statement is
     * `$this->transitions()->pluck('to_status')` **without `withTrashed()`**. The statuses
     * outside the main line are «إلغاء تام», «نواقص», the three returns and «إعادة إرسال» —
     * exactly what an archive is full of — so cascading `transitions` would draw an **empty
     * progress bar on every archived order**, as though nothing had ever happened to it, on the
     * first screen anybody opens. It would also break `statusBeforeCancellation()`, which reads
     * the same timeline, and with it «تراجع عن الإلغاء» after a restore.
     *
     * So the rule stands as written: a restore undoes the one delete it names. Nothing has to
     * un-cascade, because the cascade never happens.
     *
     * @return list<string>
     */
    public function softDeleteCascades(): array
    {
        return [];
    }
}
