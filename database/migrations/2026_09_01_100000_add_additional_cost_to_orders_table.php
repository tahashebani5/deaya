<?php

declare(strict_types=1);

use App\Domain\Order\Enums\AdditionalCostReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an order is charged beyond its products — packaging, transport, a change to what was
 * agreed.
 *
 * **A column of its own, never folded into `discount`.** The arithmetic would have allowed it:
 * a discount of −10 adds ten to the total just as well. It is refused because the two answer
 * different questions, and the question asked of an order months later is «لماذا تغيّر
 * الإجمالي؟» — an answer of "the discount was −10" is a puzzle, not an answer. Both figures
 * stay readable on their own, which is what the brief asked for in as many words.
 *
 * **The reason is a code, not prose.** `additional_cost_reason` holds one of
 * {@see AdditionalCostReason}, and `additional_cost_note` holds the
 * words beside it. Free text alone cannot be grouped by — «تغليف»، «تغليف خاص»، «كرتون» are one
 * category in three spellings — and it is the code that lets these orders be carried into a
 * multi-line table later with their categories intact. See ORDER-ADDITIONAL-COST.md.
 *
 * `additional_cost` is zero rather than nullable, for the reason `written_off_amount` is:
 * nothing was ever charged on top of an existing order, and that is a fact we know rather than
 * one we are missing. The two text columns *are* nullable — an order with no charge has no
 * reason, and zero has no spelling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('additional_cost', 12, 2)->default(0)->after('discount');
            $table->string('additional_cost_reason', 30)->nullable()->after('additional_cost');
            $table->string('additional_cost_note', 500)->nullable()->after('additional_cost_reason');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['additional_cost', 'additional_cost_reason', 'additional_cost_note']);
        });
    }
};
