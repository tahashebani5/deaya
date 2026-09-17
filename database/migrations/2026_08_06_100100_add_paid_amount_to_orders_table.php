<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the order has been paid, as a number the list can sort and filter on.
 *
 * **This is a cache of `order_payments`, and it is important that it is read as one.** The
 * ledger is the truth; this column is what it adds up to. It exists because the orders list
 * filters on «غير مدفوعة» and a correlated subquery per row is an N+1 wearing SQL's clothes.
 *
 * It is rewritten by `RecalculateOrderPayments` inside the same transaction that wrote the
 * entry, so the two cannot be observed disagreeing — the same arrangement `orders.items_total`
 * has with `order_items`.
 *
 * **There is deliberately no `payment_status` column beside it.** That is derived from this and
 * `grand_total` together, and `grand_total` moves whenever the lines or the discount do. A
 * stored status would need rewriting by both paths, and the first one to forget would leave an
 * order reading «مدفوعة بالكامل» after its total went up. See `PaymentStatus`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Defaulted to zero rather than nullable: every existing order has been paid
            // nothing, which is a fact we know, not one we are missing.
            $table->decimal('paid_amount', 12, 2)->default(0)->after('grand_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
