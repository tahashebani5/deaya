<?php

use App\Domain\Identity\Enums\PermissionName;
use App\Providers\AppServiceProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Hands `orders.partial_delivery` to every role that can already mark an order delivered.
 *
 * **This migration is half of Decision 5**, and without it that decision is the wrong one. See
 * PARTIAL-DELIVERY-DESIGN.md §3. The question was whether recording a partial delivery should
 * ride on `orders.status.delivered` or earn a grant of its own, and the answer turned on a fact
 * about *this* application rather than about trust: reusing a permission widens it for everybody
 * already holding it, silently, without the business ticking a box — and welds the two powers
 * together so that stopping one person shrinking invoices would mean stopping them marking
 * anything delivered at all.
 *
 * A new permission avoids both, at the cost of a feature nobody can find until somebody
 * remembers to grant it. This is what pays that cost: **the same people, on day one, through a
 * switch that can be thrown on its own.**
 *
 * **Scoped to the roles that already deliver, not to every role** — which is where it parts
 * company with `grant_business_field_view_to_every_role`, the migration it is otherwise copied
 * from. That one hands out a *read* the customer form cannot work without. This hands out the
 * power to reduce what a customer owes, and a role that was never trusted to hand a parcel over
 * has no business acquiring it by upgrade.
 *
 * Roles are read from the database rather than named: {@see RoleSeeder} calls itself «a starting
 * point, not a policy» and seeds only two, so the roles that actually exist in a running shop
 * were built from the roles screen and this migration has never heard of them. Administrators
 * are not listed because the gate in {@see AppServiceProvider} already grants them everything.
 *
 * Idempotent, and it runs before `permissions:sync` in `deploy.sh` — hence `findOrCreate` rather
 * than a lookup that would find nothing on a server whose permission rows are one command behind
 * the code.
 */
return new class extends Migration
{
    public function up(): void
    {
        $partialDelivery = Permission::findOrCreate(PermissionName::RecordPartialDelivery->value, 'web');

        $delivered = Permission::query()
            ->where('name', PermissionName::MarkOrdersDelivered->value)
            ->where('guard_name', 'web')
            ->first();

        // A database where nobody can mark an order delivered yet — a fresh install mid-seed —
        // has nobody to grant this to either. `RoleSeeder` covers that case.
        if ($delivered === null) {
            return;
        }

        // The pivot is written directly rather than through `givePermissionTo`, which reads a
        // role's existing permissions and so trips the application's lazy-loading guard inside a
        // migration. `insertOrIgnore` gives the same idempotence the guard would have.
        $rows = DB::table('role_has_permissions')
            ->where('permission_id', $delivered->getKey())
            ->pluck('role_id')
            ->map(fn (int $roleId) => ['role_id' => $roleId, 'permission_id' => $partialDelivery->getKey()])
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
            ->where('name', PermissionName::RecordPartialDelivery->value)
            ->where('guard_name', 'web')
            ->first();

        if ($permission === null) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permission->getKey())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
