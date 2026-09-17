<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every labour, machine-runtime, overhead or scrap cost incurred producing an order.
 *
 * **A ledger, not a column** — exactly the reasoning `order_payments` already carries for money
 * and `stock_movements` carries for quantity. Rows are written once and never edited; a mistake
 * is undone by a further row pointing back at the one it corrects (`reverses_entry_id`, the same
 * shape `order_payments.reverses_payment_id` uses), not by rewriting history.
 *
 * `order_item_id` is nullable: a rate-applied entry always names the line it was computed for,
 * but a cost genuinely shared across an order's lines (electricity for the whole print run) has
 * nowhere truer to sit than the order itself until somebody decides how to allocate it — that
 * allocation is not built here.
 *
 * `quantity`/`rate` are null for a scrap-loss entry, which is derived from FIFO batch cost rather
 * than a rate applied to a quantity — see `RecordScrapLoss` (not part of this change) and the
 * plan's Inventory section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_cost_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->cascadeOnDelete();

            $table->string('cost_type', 20);   // ManufacturingCostType

            $table->decimal('quantity', 12, 3)->nullable();
            $table->decimal('rate', 12, 3)->nullable();

            // Force-filled, never client-typed — the product of quantity and rate, or the
            // FIFO-derived cost of a scrap loss.
            $table->decimal('amount', 14, 2);

            $table->foreignId('recorded_by')->nullable()->constrained('users');
            $table->timestamp('incurred_at');

            $table->foreignId('reverses_entry_id')->nullable()
                ->constrained('production_cost_entries')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            // An order's own cost breakdown, which is how this table is read on a screen.
            $table->index(['order_id', 'incurred_at']);
        });

        DB::statement(
            'ALTER TABLE production_cost_entries
             ADD CONSTRAINT production_cost_entries_amount_not_negative CHECK (amount >= 0)'
        );

        // One reversal per entry, ever — the same guarantee order_payments makes, for the same
        // reason: the check alone loses to two concurrent requests racing past it.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX production_cost_entries_reverses_entry_id_unique
                ON production_cost_entries (reverses_entry_id)
                WHERE reverses_entry_id IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('production_cost_entries');
    }
};
