<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stock ledger: every reason a quantity ever changed, and who changed it.
 *
 * **Append-only in practice.** Nothing updates or deletes a row — there is no route that does,
 * and a correction is recorded as a further `adjustment` rather than by editing history. The
 * table still carries `deleted_at` because every table in this schema does and a test enforces
 * it, not because anything deletes from it.
 *
 * `quantity` is always positive; direction is carried by *which end is filled in*:
 *
 *   purchase_arrival    from = null   to = warehouse    stock enters the business
 *   internal_transfer   from = a      to = b            stock moves between two of our sites
 *   order_fulfillment   from = a      to = null         stock leaves for a customer
 *   adjustment          exactly one side                a stocktake correction
 *
 * Storing the sign in the shape rather than in the number means a total is never accidentally
 * summed the wrong way round, and "which warehouse lost this" is a `where`, not a case
 * expression.
 *
 * `employee_id` looks like a duplicate of the audit trail's `causer_id`, and it is — on purpose.
 * The ledger has to stay readable on its own: reconciling a shelf should not require joining
 * `activity_log`, and the trail is free to move to another store one day (see AuditService)
 * while this column stays where the balance it explains lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();

            // Nullable ends, each meaning "outside the business" — see the table above.
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            $table->decimal('quantity', 12, 3);

            $table->string('movement_type', 20)->index();   // MovementType

            // The order this movement belongs to, once Orders lands. Deliberately *not* a
            // foreign key: `orders` does not exist yet, and a constraint cannot point at a
            // table that is not there. 🎯 Add the FK in a follow-up migration alongside that
            // table — it is a two-line change, and leaving the column unconstrained until then
            // is honest about what is and is not guaranteed today.
            $table->unsignedBigInteger('reference_id')->nullable()->index();

            // Who moved it. Assigned from the authenticated user, never from the payload.
            $table->foreignId('employee_id')->constrained('users');

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            // The three questions asked of this table: one size's history, and what came into
            // or went out of one warehouse. `created_at` is in each so the newest page is read
            // straight from the index.
            $table->index(['product_variant_id', 'created_at']);
            $table->index(['from_warehouse_id', 'created_at']);
            $table->index(['to_warehouse_id', 'created_at']);
        });

        // A movement of nothing is not a movement, and a zero row would sit in the ledger
        // looking like an explanation for a balance that never changed.
        DB::statement(
            'ALTER TABLE stock_movements
             ADD CONSTRAINT stock_movements_quantity_is_positive CHECK (quantity > 0)'
        );

        // Both ends null would be stock leaving nowhere and arriving nowhere — a row the
        // reconciliation query would count and never be able to attribute.
        DB::statement(
            'ALTER TABLE stock_movements
             ADD CONSTRAINT stock_movements_has_an_end
             CHECK (from_warehouse_id IS NOT NULL OR to_warehouse_id IS NOT NULL)'
        );

        // A transfer from a warehouse to itself changes no balance while claiming to. Refused
        // with a readable 422 too; this is the guarantee under it.
        DB::statement(
            'ALTER TABLE stock_movements
             ADD CONSTRAINT stock_movements_ends_differ
             CHECK (from_warehouse_id IS NULL OR to_warehouse_id IS NULL
                    OR from_warehouse_id <> to_warehouse_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
