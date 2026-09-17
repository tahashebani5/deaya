<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صفقة — one purchase of stock, financed by one or more investors.
 *
 * **It carries no money at all.** No capital total, no cost, no sales, no profit, no quantity.
 * Every one of those is derived: the stock from `stock_batches.investor_deal_id`, the money
 * from `investor_wallet_entries`. That is the standing rule of this schema — «الرصيد لا
 * يُخزَّن» — and the reason `orders.paid_amount` may only ever be written by one action.
 *
 * **And no `warehouse_id`.** The first transfer of half the goods would make it a lie; the
 * layers know where they are.
 *
 * `investor_profit_share_percent` is the investors' share of this deal's profit, seeded from
 * `company_settings` at creation and frozen the moment the deal leaves `draft`. Editing the
 * company default tomorrow moves nothing here, which is exactly why the default is safe to
 * have: renegotiating a live deal is a new deal, not an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_deals', function (Blueprint $table) {
            $table->id();

            // «D25» — the same allocation the order number uses.
            $table->string('code', 12);

            $table->string('name', 120);

            // A label for the screen and nothing more. Attribution runs entirely through the
            // stock items in `investor_deal_items`, because a product does not own stock in this
            // system — a shelf does, and one shelf can stand behind several products.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('status', 20)->default('draft');

            $table->decimal('investor_profit_share_percent', 5, 2);

            $table->date('opened_on');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['status', 'opened_on']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX investor_deals_code_unique ON investor_deals (code)
            WHERE deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE investor_deals
            ADD CONSTRAINT investor_deals_share_percent_range
            CHECK (investor_profit_share_percent >= 0 AND investor_profit_share_percent <= 100)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_deals');
    }
};
