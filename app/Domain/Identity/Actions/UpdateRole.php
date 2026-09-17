<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Exceptions\SystemRoleCannotBeModified;
use App\Domain\Identity\Models\Role;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Renames a role and/or replaces its permission set.
 */
final class UpdateRole
{
    public function __construct(
        private readonly RecordRolePermissionChange $recordPermissionChange,
        private readonly EnsurePermissionsExist $ensurePermissionsExist,
    ) {}

    /**
     * @param  list<string>|null  $permissions  null leaves the current set untouched.
     */
    public function __invoke(Role $role, string $name, ?array $permissions = null): Role
    {
        $isAdminRole = $role->name === RoleName::Admin->value;

        // The gate looks the administrator role up by name, so renaming it would silently
        // revoke every administrator's access.
        if ($isAdminRole && $name !== $role->name) {
            throw SystemRoleCannotBeModified::renamed($role->name);
        }

        // Granting permissions to a role that already passes everything would create a list
        // that looks meaningful and changes nothing.
        if ($isAdminRole && $permissions !== null && $permissions !== []) {
            throw SystemRoleCannotBeModified::permissionsChanged($role->name);
        }

        DB::transaction(function () use ($role, $name, $permissions): void {
            $role->update(['name' => $name]);

            if ($permissions !== null) {
                // Before the sync, for the same reason as in CreateRole: a permission the code
                // declares and validation accepts must be grantable, whether or not the seeder
                // has caught up.
                ($this->ensurePermissionsExist)($permissions);

                // Read before the sync, because afterwards there is nothing left to compare
                // against — the pivot table keeps no history of its own.
                $before = $role->permissions()->pluck('name')->all();

                $role->syncPermissions($permissions);

                ($this->recordPermissionChange)($role, $before, $permissions);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $role->load('permissions');
    }
}
