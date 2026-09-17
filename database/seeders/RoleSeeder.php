<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission catalogue, and the roles the system starts with.
 *
 * Permissions come from {@see PermissionName} — the code defines them, because a permission is
 * only real when something checks for it. Roles are seeded with a starting shape and are then
 * entirely the administrator's to change through the API.
 *
 * Idempotent: safe to re-run.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RoleName::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        // The administrator is granted nothing on purpose: the gate in AppServiceProvider gives
        // that role everything, so listing permissions here would be duplicated truth that can
        // drift out of step with the catalogue.

        // A starting point, not a policy — day-to-day staff serve customers but do not set
        // prices or hand out access. Change it from the API; nothing in the code depends on it.
        Role::findByName(RoleName::Staff->value, 'web')->syncPermissions([
            PermissionName::ViewCustomers->value,
            PermissionName::ManageCustomers->value,
            // Not a policy choice: the customer form has a «مجال العمل» picker on every shop
            // row, and it cannot be filled in without the list. Curating that list is the
            // separate, rarer job and stays with the administrator.
            PermissionName::ViewBusinessFields->value,
            PermissionName::ViewProducts->value,
            // Needed to take an order at all — the city and region lists are what an address is
            // chosen from. Curating that map is a separate, rarer job.
            PermissionName::ViewDeliveryLocations->value,
            // Reading stock, not moving it: someone taking an order needs to know whether the
            // size is on the shelf. Recording a transfer or a stocktake is the storekeeper's
            // job, and `inventory.manage` is what the business grants when it decides who that is.
            PermissionName::ViewInventory->value,
            // **`orders.delete`, `orders.restore` and `orders.archive.view` are deliberately not
            // here**, and the omission is the decision rather than an oversight. This role does
            // not hold `orders.view` to begin with, so an archive it could open would be a screen
            // listing orders it may not read; and of the three, two move stock — a delete puts
            // goods back on the shelf and a restore takes them off again at today's cost. Who is
            // trusted with that is the business's answer to give from the roles screen, and the
            // administrator satisfies all three by rule in the meantime.
            //
            // **`shortages.*` is absent for a narrower reason.** Reading and chasing shortages is
            // plausibly this role's work — but two of the five spend money, and which employee is
            // trusted to hand over cash for a sack is not a question a seeder should answer on the
            // business's behalf. The pair that are safe are no use without the three that are not,
            // so the whole group waits for the roles screen.
        ]);

        // «محاسب» is left deliberately empty — it is the worked example of a role waiting for
        // the business to decide what it may do.

        // Spatie caches roles and permissions; without this, anything created here would be
        // invisible to checks made later in the same process.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
