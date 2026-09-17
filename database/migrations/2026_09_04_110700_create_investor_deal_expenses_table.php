<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A deal's costs, kept as a log of rows rather than a total — «حتى يمكن معرفة مصدر كل تكلفة».
 *
 * `purchase_order_additional_costs` cannot serve: it is scoped to one order and
 * `UpdatePurchaseOrder` refuses any edit the instant anything has arrived, so «أضف فاتورة الشحن
 * حين تصل» hits a wall there. A late invoice lands here.
 *
 * **`is_landed` is the most important column and the server alone decides it.** Shipping and
 * customs entered on a purchase order are *already* inside the deal's cost of goods —
 * `AllocatePurchaseOrderAdditionalCosts` proportions them into `final_unit_cost`, which becomes
 * `stock_batches.unit_cost`, which is snapshotted into every consumption row. A landed row is
 * kept so the origin of the cost is visible, and is **never subtracted again**: doing so pays
 * the investor for one shipping invoice twice.
 *
 * Append-only. A correction is a reversing row carrying the original amount verbatim with a
 * mandatory reason, exactly as `ReverseOrderPayment` writes one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_deal_expenses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('investor_deal_id')->constrained('investor_deals')->cascadeOnDelete();

            $table->string('kind', 20);
            $table->string('name', 120);
            $table->decimal('amount', 14, 2);

            $table->boolean('is_landed')->default(false);

            $table->date('incurred_on');

            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->foreignId('reverses_expense_id')->nullable()
                ->constrained('investor_deal_expenses')->nullOnDelete();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['investor_deal_id', 'incurred_on']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE investor_deal_expenses
            ADD CONSTRAINT investor_deal_expenses_amount_positive CHECK (amount > 0)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX investor_deal_expenses_reverses_expense_id_unique
            ON investor_deal_expenses (reverses_expense_id)
            WHERE reverses_expense_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        // A purchase order received in three shipments must not write its customs invoice three
        // times. Same law as the earning index next door.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX investor_deal_expenses_one_row_per_source
            ON investor_deal_expenses (investor_deal_id, source_type, source_id)
            WHERE source_type IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_deal_expenses');
    }
};
