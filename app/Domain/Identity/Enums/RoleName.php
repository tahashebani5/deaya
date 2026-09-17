<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * The roles the *code* knows about by name.
 *
 * Roles themselves live in the database, so the business can add as many as it likes at
 * runtime. This enum only covers the ones something in the codebase has to reference — chiefly
 * Admin, which the authorization gate treats specially. Anything else is data, not a constant.
 */
enum RoleName: string
{
    /** Full access to everything, always — see AppServiceProvider's Gate::before. */
    case Admin = 'admin';

    /** The base employee role. Starts with no permissions; they get granted as needed. */
    case Staff = 'staff';

    /** An example of a job-specific role, ready to have permissions attached to it. */
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'مدير',
            self::Staff => 'موظف',
            self::Accountant => 'محاسب',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
