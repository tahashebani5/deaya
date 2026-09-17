<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Actions;

use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Exceptions\ShortageTransitionNotAllowed;
use App\Domain\Shortage\Models\Shortage;

/**
 * Moves a shortage along the chase, or refuses to.
 *
 * The only place a person's choice of status is written. What is legal is {@see ShortageStatus}'s
 * to say and nothing else holds an opinion — a second copy of those rules is a second thing to
 * keep in step, and the copy that drifts is always the one guarding the write.
 *
 * **Two statuses this cannot reach, for two different reasons.** «مكتمل» is the arithmetic's —
 * {@see RecalculateShortageTotals} writes it when the last of the quantity comes back, and a
 * request naming it is refused with a message that says so rather than one listing it as a
 * destination to try later. And no map leads back to «جديد»: «لم تبدأ متابعته بعد» stops being
 * true the moment somebody starts, and a status that can be un-started would make «جديد: ١٢» on
 * the board a number that goes up for reasons nobody did.
 *
 * No transitions table beside this one. An order has `order_status_transitions` because «كم يوماً
 * تقعد الطلبية عند المورد؟» is a question the business asks and a status column cannot answer;
 * nobody has yet asked how long a shortage sat in «جاري البحث», and `ActivityLog` already records
 * every change with its actor and its timestamp. The day that question is asked, the log is where
 * the answer already is.
 */
final class ChangeShortageStatus
{
    /**
     * @throws ShortageTransitionNotAllowed
     */
    public function __invoke(Shortage $shortage, ShortageStatus $target): Shortage
    {
        if (! $shortage->status->canMoveTo($target)) {
            throw ShortageTransitionNotAllowed::make($shortage->status, $target);
        }

        $shortage->forceFill(['status' => $target])->save();

        return $shortage;
    }
}
