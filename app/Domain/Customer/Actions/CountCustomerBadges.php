<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Enums\CustomerBadge;
use App\Domain\Support\SupportService;

/**
 * Every badge, counted for one customer, in one answer.
 *
 * **The `match` has no `default`, and that is the whole design.** A badge added to
 * {@see CustomerBadge} fails the build here until somebody says how to count it — which is the
 * only way a list like this stays honest. A `default => 0` would let a new badge ship silently
 * stuck at zero, and a badge that never lights up is indistinguishable from one nobody
 * implemented.
 *
 * **Each arm asks the owning context through its front door**, never a query of its own.
 * Support's unread rule lives in `SupportService` beside the per-ticket one; copying it here
 * would put the same rule in two places, and the copy is always the one that drifts.
 *
 * **Zeros are returned, not omitted.** The app draws from this map, so a badge that has just
 * been cleared has to be told it is over — an answer carrying only the non-empty ones would
 * leave the last number on a tile forever.
 */
final class CountCustomerBadges
{
    public function __construct(private readonly SupportService $support) {}

    /**
     * @return array<string, int> keyed by {@see CustomerBadge::value}
     */
    public function __invoke(int $customerId): array
    {
        $counts = [];

        foreach (CustomerBadge::cases() as $badge) {
            $counts[$badge->value] = match ($badge) {
                CustomerBadge::Support => $this->support->countUnreadForCustomer($customerId),
            };
        }

        return $counts;
    }
}
