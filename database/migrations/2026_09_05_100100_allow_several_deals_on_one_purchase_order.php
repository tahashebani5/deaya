<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One purchase order, several deals — one per group of lines.
 *
 * The unique index added hours earlier said «أمر واحد، صفقة واحدة», and that was a restriction
 * invented by the funding screen rather than one the model ever had:
 * `investor_deal_supplies` has been keyed `(source_type, source_id, stock_item_id)` since the
 * feature shipped, so **the claim is per line**, and two deals may fund two lines of one lorry.
 *
 * The plain index stays, because «أرِني صفقات هذا الأمر» is the question the purchase-order
 * screen now asks on every read. What still cannot happen — enforced where it always was, on
 * the supplies table — is two deals claiming the *same* line.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS investor_deals_purchase_order_unique');

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS investor_deals_purchase_order_index
            ON investor_deals (purchase_order_id)
            WHERE purchase_order_id IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS investor_deals_purchase_order_index');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX investor_deals_purchase_order_unique
            ON investor_deals (purchase_order_id)
            WHERE purchase_order_id IS NOT NULL AND deleted_at IS NULL
        SQL);
    }
};
