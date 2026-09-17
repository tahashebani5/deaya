<?php

use App\Console\Commands\SyncPermissions;
use App\Domain\Identity\Enums\PermissionName;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the `billboards.manage` row.
 *
 * **Created and granted to nobody**, unlike `grant_business_field_view_to_every_role`. That one
 * handed its permission out because every member of staff opening a customer form needed the
 * list the same day. This one is the opposite: deciding what the shop puts in front of its
 * customers is a marketing job, not a job everybody has, so it starts ungranted and the
 * administrator ticks it onto whichever role should hold it. Administrators satisfy it already —
 * the gate in {@see AppServiceProvider} grants them everything.
 *
 * The row has to exist for the permission to be grantable at all: Spatie refuses
 * `givePermissionTo()` for a name it has never seen, so a permission that lives only in
 * {@see PermissionName} is one the roles screen cannot offer. {@see SyncPermissions} does this
 * for a running database; this covers migrations and the test suite, which runs them and no
 * seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate(PermissionName::ManageBillboards->value, 'web');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->where('name', PermissionName::ManageBillboards->value)
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
