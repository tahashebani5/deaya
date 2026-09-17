<?php

use App\Domain\Identity\Enums\PermissionName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds every unique index as a *partial* one that only sees live rows.
 *
 * Soft deletes and a plain unique index disagree about what a table contains. `deleted_at`
 * says a row is gone; the index still counts it. Deleting the city طرابلس and adding it back
 * would fail on `cities_name_unique` against a row the API insists does not exist — and it
 * would fail as a 500 from a constraint violation, not as a readable 422, because every
 * `unique:` validation rule ignores trashed rows exactly as the model does.
 *
 * PostgreSQL's partial indexes settle it: the index is the same guarantee, evaluated only over
 * rows where `deleted_at is null`. A name is released the moment its holder is deleted, and
 * two live rows still cannot share one. This is the second place (after the customer code
 * sequence) where the schema depends on PostgreSQL specifically.
 *
 * **Names are kept.** `cities_name_unique` describes the same rule it always did, and Laravel's
 * `dropUnique(['name'])` still finds it by convention.
 *
 * `permissions_name_guard_name_unique` is deliberately untouched: permissions are defined by
 * {@see PermissionName}, are never deleted at runtime, and their
 * table carries no `deleted_at` to filter on.
 */
return new class extends Migration
{
    /**
     * index name => [table, columns, extra condition beyond "not deleted"]
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}>
     */
    private const UNIQUE_INDEXES = [
        'users_email_unique' => ['users', 'email', null],
        'users_phone_unique' => ['users', 'phone', null],
        'customers_code_unique' => ['customers', 'code', null],
        'customers_phone_unique' => ['customers', 'phone', null],
        'products_slug_unique' => ['products', 'slug', null],
        'product_variants_product_id_label_unique' => ['product_variants', 'product_id, label', null],
        'product_price_tiers_product_variant_id_min_quantity_unique' => ['product_price_tiers', 'product_variant_id, min_quantity', null],
        'cities_name_unique' => ['cities', 'name', null],
        'regions_city_id_name_unique' => ['regions', 'city_id, name', null],
        'roles_name_guard_name_unique' => ['roles', 'name, guard_name', null],

        // Already partial: at most one primary image per product. It gains the second half of
        // its condition rather than being converted — a deleted image must stop blocking the
        // promotion of the one that replaced it.
        'product_images_one_primary_per_product' => ['product_images', 'product_id', 'is_primary'],
    ];

    public function up(): void
    {
        foreach (self::UNIQUE_INDEXES as $name => [$table, $columns, $condition]) {
            $this->drop($table, $name);

            $where = $condition === null ? '' : $condition.' AND ';

            DB::statement(
                "CREATE UNIQUE INDEX {$name} ON {$table} ({$columns}) WHERE {$where}deleted_at IS NULL"
            );
        }
    }

    public function down(): void
    {
        foreach (self::UNIQUE_INDEXES as $name => [$table, $columns, $condition]) {
            $this->drop($table, $name);

            if ($condition !== null) {
                DB::statement("CREATE UNIQUE INDEX {$name} ON {$table} ({$columns}) WHERE {$condition}");

                continue;
            }

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} UNIQUE ({$columns})");
        }
    }

    /**
     * Some of these were created by `$table->unique()` and are backed by a constraint; the
     * partial ones are bare indexes. Dropping the constraint takes its index with it, so both
     * statements together remove either shape without having to ask the catalogue which it is.
     */
    private function drop(string $table, string $name): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        DB::statement("DROP INDEX IF EXISTS {$name}");
    }
};
