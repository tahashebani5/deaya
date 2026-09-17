<?php

use App\Console\Commands\SyncPermissions;
use App\Domain\Identity\Enums\PermissionName;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the `support.view` and `support.manage` rows.
 *
 * Granted to nobody, like `billboards.manage` before them: who answers customers is a job the
 * business assigns, not one everybody already has. Administrators satisfy both already — the
 * gate in {@see AppServiceProvider} grants them everything.
 *
 * The rows have to exist for the permissions to be grantable at all: Spatie refuses
 * `givePermissionTo()` for a name it has never seen, so one that lives only in
 * {@see PermissionName} is one the roles screen cannot offer. {@see SyncPermissions} covers a
 * running database; this covers migrations and the test suite, which runs them and no seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([PermissionName::ViewSupportTickets, PermissionName::ManageSupportTickets] as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()
            ->whereIn('name', [
                PermissionName::ViewSupportTickets->value,
                PermissionName::ManageSupportTickets->value,
            ])
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
