<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Events\ShortageAssigned;
use App\Domain\Shortage\Models\Shortage;

/**
 * Puts a shortage in somebody's queue, moves it to somebody else's, or takes it out of all of
 * them.
 *
 * **One action for all three**, because they are one fact with three values — assigning,
 * reassigning and unassigning differ only in what the column ends up holding, and splitting them
 * would mean three endpoints and three permissions for one column.
 *
 * **«جديد» becomes «جاري البحث» on the way.** «لم تبدأ متابعته بعد» stops being true the moment a
 * shortage lands in somebody's queue, and leaving it in «جديد» would make the board's first
 * number count work that has in fact started. This is the one place a status changes without
 * anybody choosing it — and it is deliberately not a transition on the map for that reason: what
 * the map governs is what a person may pick.
 *
 * **Unassigning does not move it back.** The chase started, and the shortage goes back into the
 * pool still open rather than pretending it was never picked up.
 */
final class AssignShortage
{
    public function __invoke(Shortage $shortage, ?User $assignee, ?User $actor = null): Shortage
    {
        $attributes = ['assigned_to_user_id' => $assignee?->getKey()];

        if ($assignee !== null && $shortage->status === ShortageStatus::New) {
            $attributes['status'] = ShortageStatus::Searching;
        }

        $shortage->forceFill($attributes)->save();

        // **After the write, and only for a real assignment.** Unassigning is not news anybody
        // needs on their phone, and telling somebody their work was taken away is a different
        // message that nobody has asked for. Queued after commit, like every other notification
        // here — one about a transaction that rolled back must never have been sent.
        if ($assignee !== null) {
            ShortageAssigned::dispatch(
                (int) $shortage->getKey(),
                (int) $assignee->getKey(),
                $actor === null ? null : (int) $actor->getKey(),
            );
        }

        return $shortage;
    }
}
