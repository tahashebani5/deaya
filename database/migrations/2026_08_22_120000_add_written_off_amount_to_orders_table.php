<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the business decided it is not going to collect.
 *
 * **A second cache of `order_payments`, beside `paid_amount` and deliberately not merged with
 * it.** The obvious shortcut was to let a write-off add to `paid_amount` — one column, one sum,
 * and the settlement guard passes. It was rejected because that column answers «كم قبضنا؟», and
 * an order that took 105 of 110 would start answering «110». The five dinars that never arrived
 * would be indistinguishable from five that did, in the drawer report, in the P&L, and in every
 * future query nobody has written yet.
 *
 * So the ledger has a fourth entry type and the order has a second total. Both are written by
 * `RecalculateOrderPayments` inside the transaction that wrote the entry, so neither can be
 * observed disagreeing with the rows it sums — the same arrangement `paid_amount` already has.
 *
 * **What is owed is now `grand_total − paid_amount − written_off_amount`**, and that is the
 * number the settlement guard reads. See `Order::remainingAmount()` and `PaymentStatus`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Zero rather than nullable, for the reason `paid_amount` is: nothing has been
            // written off against any existing order, and that is a fact we know rather than one
            // we are missing.
            $table->decimal('written_off_amount', 12, 2)->default(0)->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('written_off_amount');
        });
    }
};
