<?php

declare(strict_types=1);

namespace App\Domain\Notification\Audience;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Investor\InvestorService;

/**
 * Turns an audience into the people it actually means.
 *
 * The only place in the application that answers "who should hear about this", which is what
 * keeps every definition down to one line about its audience.
 */
final readonly class ResolveRecipients
{
    public function __construct(private InvestorService $investors) {}

    /**
     * @return list<int> user ids, distinct, with the causer removed unless they asked to be kept
     */
    public function handle(NotificationAudience $audience, ?int $causerId, bool $notifyCauser): array
    {
        $ids = match ($audience->kind) {
            AudienceKind::Permission => $this->holdersOfPermission($audience->permission->value),
            AudienceKind::User => [$audience->userId],
            AudienceKind::Role => $this->holdersOfRole($audience->roleId),
            AudienceKind::Everyone => $this->everyEmployee(),
        };

        if (! $notifyCauser && $causerId !== null) {
            $ids = array_filter($ids, fn (int $id) => $id !== $causerId);
        }

        return array_values(array_unique($ids));
    }

    /**
     * Everybody the gate would let through — **which is not the same as everybody holding the
     * permission row**, and getting that wrong is a silent, total failure.
     *
     * `RoleSeeder` grants the administrator **nothing**: «the gate in AppServiceProvider gives
     * that role everything, so listing permissions here would be duplicated truth». So an
     * administrator holds no `role_has_permissions` rows at all, and a plain
     * `User::permission(...)` finds none of them — every administrator would silently never
     * receive a single notification, with no error anywhere to say so.
     *
     * The union below is the same answer `UserResource` reaches by asking the gate case by case,
     * and for the same stated reason: only asking about both kinds of account is true for both.
     * Doing it as one query rather than `Gate::allows` per user keeps this O(1) as the staff
     * list grows.
     *
     * @return list<int>
     */
    private function holdersOfPermission(string $permission): array
    {
        return User::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($roles) => $roles->where('name', RoleName::Admin->value))
                ->orWhereHas('roles.permissions', fn ($p) => $p->where('name', $permission))
                ->orWhereHas('permissions', fn ($p) => $p->where('name', $permission)))
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<int>
     */
    private function holdersOfRole(int $roleId): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->whereKey($roleId))
            ->pluck('id')
            ->all();
    }

    /**
     * Every active employee — **investors excluded**.
     *
     * An investor with a login is not staff: «اجتماع الساعة ٤» is not addressed to the person
     * whose money is in the stock, and sending it to them leaks the fact that a meeting is
     * happening at all.
     *
     * Excluded by the `investors.user_id` link rather than by role name, following the rule
     * `UserResource` states where it derives `is_investor`: the link is «a fact about a row
     * rather than the name of a role», so renaming «مستثمر» cannot quietly put investors back
     * into every announcement.
     *
     * @return list<int>
     */
    private function everyEmployee(): array
    {
        $investorUserIds = $this->investors->linkedUserIds();

        return User::query()
            ->where('is_active', true)
            ->when($investorUserIds !== [], fn ($query) => $query->whereNotIn('id', $investorUserIds))
            ->pluck('id')
            ->all();
    }
}
