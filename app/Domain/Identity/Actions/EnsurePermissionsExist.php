<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\PermissionName;
use Spatie\Permission\Models\Permission;

/**
 * Materialises catalogue rows for permissions the code declares but the database has not got yet.
 *
 * **Why this is needed at all.** {@see PermissionName} is the catalogue — a permission is real
 * because code checks for it — and the rows in `permissions` are only a copy of it, written by
 * `RoleSeeder`. The two go out of step the moment a release adds a case and the seeder has not
 * been re-run, and the symptom is brutal: `StoreRoleRequest` validates against the enum and lets
 * the name through, then Spatie throws `PermissionDoesNotExist` deep inside `syncPermissions`
 * and the administrator gets a **500** for ticking a box the screen offered them.
 *
 * That is exactly what happened when the order-status permissions landed, and it will happen
 * again on the next deploy that forgets the seeder. So the invariant is stated here instead:
 * **a permission the code declares and validation accepts must be grantable.**
 *
 * It cannot invent anything. The only names that reach it have already been through
 * `Rule::in(PermissionName::values())`, and it refuses anything else outright — so this creates
 * a row the codebase already checks for, never one somebody typed.
 */
final class EnsurePermissionsExist
{
    /**
     * @param  list<string>  $permissions
     */
    public function __invoke(array $permissions): void
    {
        $catalogue = PermissionName::values();

        foreach ($permissions as $permission) {
            // Belt and braces over the form request's `Rule::in`. A name from outside the
            // catalogue is skipped rather than created, so the worst case stays what it was —
            // Spatie complaining about a permission that does not exist — rather than this
            // quietly inventing one that grants nothing and lies on a checkbox.
            if (! in_array($permission, $catalogue, true)) {
                continue;
            }

            Permission::findOrCreate($permission, 'web');
        }
    }
}
