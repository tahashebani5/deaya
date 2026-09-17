<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is in this deal, and for how much.
 *
 * `share_percent` is this investor's slice **of the investors' half** — of
 * `investor_deals.investor_profit_share_percent` — so the owner's rule is two multiplications
 * rather than one. The live rows of a deal must sum to exactly 100.0000; a row-level CHECK
 * cannot see its siblings, so that is guarded in PHP under the deal's row lock.
 *
 * A real model rather than a bare pivot: a pivot fires no model events and therefore keeps no
 * history, and «من غيّر نسبة أحمد؟» is precisely the question somebody will ask.
 *
 * `committed_amount` is the **subscription** — the number the percentage was agreed against.
 * What actually arrived is a walk of `investor_wallet_entries`, and the two are shown side by
 * side and never silently reconciled: one dinar of fact, one dinar of promise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_deal_shares', function (Blueprint $table) {
            $table->id();

            $table->foreignId('investor_deal_id')->constrained('investor_deals')->cascadeOnDelete();
            $table->foreignId('investor_id')->constrained('investors')->restrictOnDelete();

            // **The pledge, not the money.** What an investor actually has in this deal is
            // Σ allocation − Σ release on his wallet ledger, and the two are allowed to differ:
            // a man may agree to 40,000 and hand over 25,000. Naming this column `capital`
            // invited every screen to add it up as though it were cash, which is why it says
            // `committed` instead — and why nothing in any profit or capital computation reads
            // it. Zero is legal: a share can be agreed before a dinar moves.
            $table->decimal('committed_amount', 14, 2)->default('0.00');
            $table->decimal('share_percent', 7, 4);

            $table->timestamp('joined_at');
            $table->string('notes', 255)->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['investor_id', 'investor_deal_id']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX investor_deal_shares_one_row_per_investor
            ON investor_deal_shares (investor_deal_id, investor_id)
            WHERE deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE investor_deal_shares
            ADD CONSTRAINT investor_deal_shares_positive
            CHECK (committed_amount >= 0 AND share_percent > 0 AND share_percent <= 100)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_deal_shares');
    }
};
