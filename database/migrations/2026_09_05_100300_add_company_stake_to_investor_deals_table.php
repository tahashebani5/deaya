<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company as a partner for what the investors did not cover.
 *
 * The owner's rule, in his words: «50% لرأس المال — الشركة لما تحط فلوس تكون كأنها طرف تاني.
 * الشريك 1: 17 الشركة، الشريك 2: 3 عمر. الـ50% تنقسم بيناتنا.» Three men who put 3,000 into a
 * 20,000 shipment own 15% of it; the investors' half of the profit is the half of *that* 15%,
 * and the other 85% is the company's whole. Until now their half was taken from everything the
 * shipment earned, however little of it their money had bought.
 *
 * `company_stake` is «الباقي على الشركة» — the landed cost of the lines the deal funds, less what
 * the partners put in. **Derived, never typed**: a typed company amount beside a typed cost is
 * two numbers able to disagree, the exact thing `Money::allocatePercent` was introduced to
 * prevent for the partners' own percentages.
 *
 * `investor_funded_percent` is the fraction of those lines' cost the partners' money covered —
 * `allocatePercent([funded, company_stake])`, frozen with the other terms when the deal opens,
 * and the second factor every profit, loss and expense is multiplied by on its way to a wallet.
 * Frozen at funding rather than re-derived at receipt, deliberately: it is the figure both
 * parties saw on the funding screen and can check by hand, and the cost drift a customs invoice
 * causes afterwards is the company's operating risk, which its half of the profit already pays
 * for. The price of freezing it is that a funded order's lines may no longer be edited and the
 * deal takes no more capital — both refused where they would happen.
 *
 * Defaults of 0 and 100 make every deal that exists today, and every deal assembled by hand
 * without a purchase order, behave exactly as before: the partners own all of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_deals', function (Blueprint $table) {
            $table->decimal('company_stake', 14, 2)->default('0.00')->after('investor_profit_share_percent');
            $table->decimal('investor_funded_percent', 7, 4)->default('100.0000')->after('company_stake');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE investor_deals
            ADD CONSTRAINT investor_deals_company_stake_positive
            CHECK (company_stake >= 0)
        SQL);

        // Never zero: a deal whose partners bought none of the goods is not a deal, and
        // `FundPurchaseOrder` refuses a stake under 1,000 long before this could be reached.
        DB::statement(<<<'SQL'
            ALTER TABLE investor_deals
            ADD CONSTRAINT investor_deals_funded_percent_range
            CHECK (investor_funded_percent > 0 AND investor_funded_percent <= 100)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE investor_deals DROP CONSTRAINT IF EXISTS investor_deals_funded_percent_range');
        DB::statement('ALTER TABLE investor_deals DROP CONSTRAINT IF EXISTS investor_deals_company_stake_positive');

        Schema::table('investor_deals', function (Blueprint $table) {
            $table->dropColumn(['investor_funded_percent', 'company_stake']);
        });
    }
};
