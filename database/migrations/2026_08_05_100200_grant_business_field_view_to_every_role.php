<?php

use App\Domain\Identity\Enums\PermissionName;
use App\Providers\AppServiceProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hands `business_fields.view` to every role that already exists.
 *
 * The endpoint is guarded like everything else — reading is a real permission, on the route,
 * where it can be taken away from one role later without a code change. But *today* every
 * member of staff who opens a customer form needs the list to pick from, so shipping the
 * permission without granting it would break the customer form for everybody until somebody
 * remembered to tick a box.
 *
 * {@see RoleSeeder} covers a database seeded from scratch; this covers the ones already
 * running. Administrators are not listed because the gate in
 * {@see AppServiceProvider} already grants them everything.
 *
 * Idempotent, and safe to run on a database whose roles have since been renamed: it reads the
 * roles that are there rather than naming any.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate(PermissionName::ViewBusinessFields->value, 'web');

        // The pivot is written directly rather than through `givePermissionTo`, which reads a
        // role's existing permissions and so trips the application's lazy-loading guard inside
        // a migration. `insertOrIgnore` gives the same idempotence the guard would have.
        $rows = DB::table('roles')
            ->where('guard_name', 'web')
            ->pluck('id')
            ->map(fn (int $roleId) => ['role_id' => $roleId, 'permission_id' => $permission->getKey()])
            ->all();

        if ($rows !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Only the grant is undone. The permission row itself stays: it is defined by
     * {@see PermissionName}, not by this migration, and deleting it would take the
     * administrator's later decisions about it down with it.
     */
    public function down(): void
    {
        $permission = Permission::query()
            ->where('name', PermissionName::ViewBusinessFields->value)
            ->where('guard_name', 'web')
            ->first();

        if ($permission === null) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permission->getKey())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
