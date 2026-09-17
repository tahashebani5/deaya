<?php

use App\Domain\Order\Actions\ApplyManufacturingRates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a unit of production is standard-costed at — labour, machine runtime, overhead.
 *
 * Admin-managed reference data, the same shape `business_fields` already is: a short list the
 * business curates from a screen, not an enum, because a rate genuinely changes and a deployment
 * should not be the way it does. Applied automatically the moment an order enters printing — see
 * `ApplyManufacturingRates` — never typed per job.
 *
 * **No effective-date history.** Changing a rate here only affects orders costed after the
 * change; historical accuracy comes from `production_cost_entries.rate` snapshotting the value
 * that was actually applied, the same way `order_items.unit_price` snapshots a catalogue price.
 * Versioning the rate table itself is a deliberate non-goal — see the plan's deferred items.
 *
 * `product_id` null is the fallback rate for a cost type when no product-specific one exists;
 * {@see ApplyManufacturingRates} never invents a third answer — a cost
 * type with neither a specific nor a default rate is simply not applied to that line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturing_cost_rates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('cost_type', 20);   // ManufacturingCostType

            // Three places, matching every other rate/cost in this schema — a per-kilo labour
            // rate needs finer than cents.
            $table->decimal('rate_per_unit', 12, 3);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement(
            'ALTER TABLE manufacturing_cost_rates
             ADD CONSTRAINT manufacturing_cost_rates_rate_not_negative CHECK (rate_per_unit >= 0)'
        );

        // One rate per (product, cost type) — a second row for the same pair is ambiguity, not
        // data. Partial: a deleted rate releases its slot, and product_id is part of the key so
        // this coexists with the default-rate index below rather than conflicting with it.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX manufacturing_cost_rates_product_cost_type_unique
                ON manufacturing_cost_rates (product_id, cost_type)
                WHERE deleted_at IS NULL AND product_id IS NOT NULL
        SQL);

        // One default rate per cost type — a NULL product_id is not distinct across rows the way
        // a plain unique index would treat it, so this needs its own partial index rather than
        // being covered by the one above.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX manufacturing_cost_rates_default_cost_type_unique
                ON manufacturing_cost_rates (cost_type)
                WHERE deleted_at IS NULL AND product_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_cost_rates');
    }
};
