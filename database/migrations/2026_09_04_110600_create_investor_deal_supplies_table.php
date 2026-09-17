<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How arriving goods learn which deal financed them — **without the warehouse clerk choosing**.
 *
 * The claim is made in advance, by whoever manages deals, against a purchase order and a shelf.
 * `ReceivePurchaseOrder` resolves it per line at receipt, so the receiving screen keeps the
 * body it has today and the storekeeper never sees a deal field. That is the acceptance
 * criterion «الموظف لا يختار الصفقة أبداً», met by moving the decision earlier rather than by
 * hiding a field.
 *
 * **Per (document, shelf), not per document.** One shipment legitimately carries deal goods and
 * company goods, and one deal legitimately covers three sizes of one order. The partial unique
 * makes «one funder per line» a database fact.
 *
 * Investment owns the claim, so nothing is added to `purchase_orders` or `stock_arrivals` and
 * no other context writes here — the dependency runs one way, as RULES §3 requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_deal_supplies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('investor_deal_id')->constrained('investor_deals')->cascadeOnDelete();

            // A value from the audit morph map — `purchase_order` today. Never free text.
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');

            $table->foreignId('stock_item_id')->constrained('stock_items')->restrictOnDelete();

            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index('investor_deal_id');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX investor_deal_supplies_one_funder_per_line
            ON investor_deal_supplies (source_type, source_id, stock_item_id)
            WHERE deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_deal_supplies');
    }
};
