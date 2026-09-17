<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Enums\PermissionName;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Puts every permission the code checks for into the table.
 *
 * A `can:` middleware naming a permission that has no row is denied for everyone who is not an
 * administrator — a 403 on a screen that was just shipped, with nothing wrong in the code. That
 * is a deploy step, not a migration: {@see PermissionName} is the catalogue, and this brings the
 * database up to it.
 *
 * **Creates only.** It never grants, revokes, or touches roles — who may do what is the
 * administrator's, changed through the API, and a deploy has no business resetting it. That is
 * also why this exists next to {@see RoleSeeder} rather than being it: the
 * seeder re-syncs the staff role to its starting shape, which on a live system throws away
 * whatever the business decided since.
 *
 * A permission dropped from the enum is left in place: some role may still hold it, and removing
 * grants is a decision, not a cleanup.
 *
 * Idempotent: safe to run on every deploy.
 */
class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Create any permission defined in code but missing from the database';

    public function handle(): int
    {
        $existing = Permission::query()->pluck('name');

        $created = collect(PermissionName::cases())
            ->map(static fn (PermissionName $permission): string => $permission->value)
            ->diff($existing)
            ->values();

        foreach ($created as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Spatie serves permission checks from a cache; without this the rows just created stay
        // invisible until it expires.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info($created->isEmpty()
            ? 'Permissions already in step with the code.'
            : "Created {$created->count()}: {$created->implode(', ')}");

        return self::SUCCESS;
    }
}
