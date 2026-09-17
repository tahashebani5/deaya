<?php

declare(strict_types=1);

namespace App\Domain\Notification\Audience;

use App\Domain\Identity\Enums\PermissionName;

/**
 * Who hears about something.
 *
 * **The single most important type in this context, and the reason adding a notification is
 * cheap.** A definition never returns a list of users — it returns one of these, and
 * {@see ResolveRecipients} turns it into people. So nothing anywhere maintains a "who gets what"
 * list: the permission catalogue the roles screen already curates *is* the list, and a role
 * created next year receives the right notifications the day it exists, with no code change.
 *
 * **Named constructors rather than a public constructor**, so an audience can only be one of the
 * shapes below and a caller cannot invent a fifth by passing an odd combination of fields.
 *
 * **`investorFor()` is reserved and deliberately unimplemented.** It is described here rather
 * than discovered later because it is the one audience that must *not* be resolved by
 * permission: `investor_portal.view` belongs to the «مستثمر» role, so resolving it that way
 * would send one investor's money notification to every other investor — and to every
 * administrator, since `Gate::before` passes them unconditionally. When it lands it resolves
 * through `investors.user_id`, exactly as the portal's own confinement does. Typing this class
 * as a `PermissionName` today — the tempting simplification, since every shipped audience is
 * permission-based — would make that a change to every definition and every test instead of an
 * added branch.
 */
final readonly class NotificationAudience
{
    private function __construct(
        public AudienceKind $kind,
        public ?PermissionName $permission = null,
        public ?int $userId = null,
        public ?int $roleId = null,
    ) {}

    /**
     * Everybody allowed to see the thing this is about.
     *
     * The ordinary case, and the one that scales: it reuses a decision the business has already
     * made on the roles screen rather than asking it a second question about notifications.
     */
    public static function permission(PermissionName $permission): self
    {
        return new self(AudienceKind::Permission, permission: $permission);
    }

    /** One named person — what an @mention will use. */
    public static function user(int $userId): self
    {
        return new self(AudienceKind::User, userId: $userId);
    }

    /** Everybody holding one role, chosen by the sender of an announcement. */
    public static function role(int $roleId): self
    {
        return new self(AudienceKind::Role, roleId: $roleId);
    }

    /**
     * Every active employee.
     *
     * **Employees, not accounts.** Investors are excluded: an investor with a login has no
     * business being told «اجتماع الساعة ٤». See {@see ResolveRecipients}.
     */
    public static function everyone(): self
    {
        return new self(AudienceKind::Everyone);
    }
}
