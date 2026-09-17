<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Exceptions\RoleInUseCannotBeDeleted;
use App\Domain\Identity\Exceptions\SystemRoleCannotBeModified;
use App\Domain\Identity\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deletes a role the business no longer needs.
 *
 * Two refusals, both about losing access by accident rather than by decision.
 */
final class DeleteRole
{
    public function __invoke(Role $role): void
    {
        // Roles the code names cannot be removed — losing `admin` would leave nobody able to
        // grant it back.
        if (RoleName::tryFrom($role->name) !== null) {
            throw SystemRoleCannotBeModified::deleted($role->name);
        }

        $holders = $role->users()->count();

        if ($holders > 0) {
            throw RoleInUseCannotBeDeleted::make($role->name, $holders);
        }

        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
