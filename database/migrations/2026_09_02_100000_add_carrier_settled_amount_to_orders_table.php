<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer paid for delivery, to the courier, instead of to us.
 *
 * **A third cache of `order_payments`, beside `paid_amount` and `written_off_amount`, and
 * deliberately not merged with either.** It exists because of the Nawris integration: we subtract
 * our `delivery_price` from the COD before the parcel goes out, so the carrier collects that
 * amount at the door on their own account. The order is then owed a sum that no cash of ours will
 * ever close, and «تم التسوية» refuses a debt — see `SettlementRequiresFullPayment`.
 *
 * **Why not `paid_amount`.** That column answers «كم قبضنا؟», and twenty dinars that went into a
 * courier's pocket is not twenty dinars in our drawer. Folding it in would inflate every
 * cash-drawer report and every P&L reconciliation by the delivery fee of every parcel — the exact
 * mistake `written_off_amount` was split out to avoid, made a second time.
 *
 * **Why not `written_off_amount`.** That column means *a loss*: money the business decided was
 * not coming. Nothing is lost here. The customer paid in full; part of the payment simply went to
 * somebody else, by arrangement. Recording a completed sale as a write-off would post a loss for
 * every single delivery and make «كم شطبنا هذا الشهر؟» meaningless.
 *
 * **What is owed becomes `grand_total − paid_amount − written_off_amount − carrier_settled_amount`.**
 * See `Order::remainingAmount()`, `PaymentStatus::between()` and — because the rule is stated in
 * SQL too — `PaymentStatusExpression`. All three move together or the list disagrees with the
 * order it is listing.
 *
 * Written only by `RecalculateOrderPayments`, inside the transaction that wrote the entry, so the
 * total can never be observed disagreeing with the rows it sums.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Zero rather than nullable, for the reason the other two totals are: no existing
            // order has ever been settled this way, and that is a fact we know rather than one
            // we are missing.
            $table->decimal('carrier_settled_amount', 12, 2)->default(0)->after('written_off_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('carrier_settled_amount');
        });
    }
};
