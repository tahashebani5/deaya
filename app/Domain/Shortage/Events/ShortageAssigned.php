<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Events;

use App\Domain\Shortage\Actions\AssignShortage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A shortage has been put in somebody's queue.
 *
 * **An event rather than a call, for the direction rule.** The reactor is the notification
 * centre, and Shortages must not import it: Notification already reads Orders, and letting it be
 * read back would make the two contexts mutually dependent — the shape `OrderEnteredShortage`
 * exists to avoid.
 *
 * Carries the assignee as well as the actor, because the two are often the same person and the
 * notification has to be able to tell: an employee who assigns a shortage to themselves does not
 * need their phone to tell them they did. `notifiesCauser()` on the definition is what acts on
 * that, and it can only do so if both ids are here.
 *
 * Fired only for a real assignment — never for unassigning. See {@see AssignShortage}.
 */
final readonly class ShortageAssigned
{
    use Dispatchable;

    public function __construct(
        public int $shortageId,
        public int $assigneeId,
        public ?int $actorId = null,
    ) {}
}
