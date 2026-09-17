<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shipment received from a vendor — the paperwork behind one or more {@see StockMovement} rows.
 *
 * **Nothing updates or deletes one, and there is no route that does.** A `StockArrival` is the
 * document a purchase-arrival ledger entry points back to; editing it after the balances it
 * produced have already moved would let the warehouse's count and its own paperwork disagree,
 * which is exactly the discrepancy the ledger design exists to prevent. The soft-delete column is
 * here because every table in this schema carries one and a test enforces it, not because
 * anything removes a row.
 *
 * `warehouse_id` is nullable and `nullOnDelete`, not `cascadeOnDelete` — the same shape
 * `stock_movements.to_warehouse_id` already uses. A warehouse can genuinely be deleted once it
 * holds no stock, and a vendor's purchase history should survive that the same way a transfer's
 * history does; it does not describe the warehouse, it describes what was bought.
 *
 * `vendor_id` *is* `cascadeOnDelete`. Nothing in this application hard-deletes a vendor today —
 * vendors are deactivated, the same as customers — so this is a guarantee about data integrity
 * rather than something expected to fire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_arrivals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();

            // Who physically received the shipment. Stamped from the authenticated user, never
            // read from the request body, so a receipt can never be attributed to a colleague.
            $table->foreignId('received_by')->constrained('users');

            // The vendor's own document number. Nullable: some shipments arrive without one, and
            // blocking the row on it would only teach people to type a placeholder.
            $table->string('invoice_number', 100)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['vendor_id', 'created_at']);
            $table->index(['warehouse_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_arrivals');
    }
};
