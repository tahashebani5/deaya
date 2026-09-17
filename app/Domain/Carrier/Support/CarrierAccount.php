<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The synthetic account every carrier-driven change is attributed to.
 *
 * **So the audit trail can tell a courier's webhook from a person.** `ChangeOrderStatus`,
 * `RecordOrderPayment` and the activity log all want an actor, and a queued job has no signed-in
 * user for `Auditable` to pick up — left null, every status change Nawris caused would read as
 * having no author at all, which is exactly the question a history screen exists to answer.
 *
 * **It holds no permissions, deliberately.** It never needs any: `OrderStatus::permission()` is
 * enforced in a FormRequest, so it guards the HTTP route rather than the action, and this account
 * reaches the domain directly from a job. Granting it nothing means that if some future code ever
 * routes it through HTTP, it is refused rather than quietly allowed — see NAWRIS-INTEGRATION.md
 * §9.4.
 *
 * It cannot be logged into: the password is random at creation and never recorded anywhere, and
 * `is_active` is false so every guard that reads it refuses.
 */
final class CarrierAccount
{
    private const EMAIL = 'nawris@carrier.local';

    private ?User $cached = null;

    public function user(): User
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $user = User::withTrashed()->where('email', self::EMAIL)->first();

        if ($user === null) {
            $user = $this->create();
        }

        return $this->cached = $user;
    }

    private function create(): User
    {
        Log::channel('nawris')->info('nawris.account.created', [
            'message' => 'the carrier system account did not exist and was created on demand',
        ]);

        $user = new User;

        $user->forceFill([
            'name' => 'شركة نورس للتوصيل',
            'email' => self::EMAIL,
            // Unique and obviously not a real number, so nobody rings it and no real staff phone
            // can collide with it.
            'phone' => '00000000000',
            // Random and discarded. There is no password to leak because nothing here knows one.
            'password' => bcrypt(Str::random(64)),
            // Not a member of staff: every path that checks this refuses.
            'is_active' => false,
        ])->save();

        return $user;
    }
}
