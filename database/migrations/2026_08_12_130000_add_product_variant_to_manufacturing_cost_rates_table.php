<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A third, more specific tier: a rate for one exact size, not just one product.
 *
 * `ApplyManufacturingRates` now tries three answers in order — the size's own rate, then the
 * product's, then the default with neither — never a fourth. A row sets **at most one** of
 * `product_variant_id`/`product_id`; a variant-specific rate does not also repeat its product,
 * because the tier a row belongs to must be readable from which column is filled, the same
 * shape `product_id` null already meant "the default" before this migration.
 *
 * **The old default-tier index has to be rebuilt, not just left alone.** It was declared
 * `WHERE product_id IS NULL` — true of the default row, but from this migration on also true of
 * every variant-specific row, which has no `product_id` of its own either. Left as it was, the
 * *second* variant-specific rate for a cost type would collide with the database's idea of "the
 * default already exists" instead of the variant-tier index that actually governs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manufacturing_cost_rates', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()
                ->after('product_id')
                ->constrained('product_variants')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE manufacturing_cost_rates
                ADD CONSTRAINT manufacturing_cost_rates_variant_xor_product
                CHECK (product_variant_id IS NULL OR product_id IS NULL)
        SQL);

        // One rate per (variant, cost type) — the same partial-unique shape the product tier and
        // the default tier already use.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX manufacturing_cost_rates_variant_cost_type_unique
                ON manufacturing_cost_rates (product_variant_id, cost_type)
                WHERE deleted_at IS NULL AND product_variant_id IS NOT NULL
        SQL);

        // Rebuilt with the third column in its WHERE clause — see the docblock above.
        DB::statement('DROP INDEX manufacturing_cost_rates_default_cost_type_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX manufacturing_cost_rates_default_cost_type_unique
                ON manufacturing_cost_rates (cost_type)
                WHERE deleted_at IS NULL AND product_id IS NULL AND product_variant_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('manufacturing_cost_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });

        DB::statement('DROP INDEX IF EXISTS manufacturing_cost_rates_default_cost_type_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX manufacturing_cost_rates_default_cost_type_unique
                ON manufacturing_cost_rates (cost_type)
                WHERE deleted_at IS NULL AND product_id IS NULL
        SQL);
    }
};
