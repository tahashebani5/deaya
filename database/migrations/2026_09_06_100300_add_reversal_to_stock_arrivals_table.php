<?php

use App\Domain\Vendor\Actions\ReverseStockArrival;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * That a receipt was entered in error, and undone.
 *
 * **Stamped onto the document rather than deleting it.** A `stock_arrival` is paperwork with a
 * ledger underneath it — the same reasoning that leaves it without an update or a destroy route —
 * so «هذه الشحنة سُجّلت بالخطأ» is a fact recorded *about* the shipment, beside it, never a fact
 * erased by removing the row. What actually left the shelf is a further `arrival_reversal`
 * movement per line; these three columns are what let a screen say so without joining the ledger
 * to find out.
 *
 * Null on every arrival anybody ever got right, which is nearly all of them. Written once, by
 * {@see ReverseStockArrival}, and never cleared: a reversed receipt is re-entered as a *new*
 * arrival, not by un-reversing this one.
 *
 * `reversal_reason` is required by the request that reaches here rather than by the column,
 * which stays nullable so the three move together — a row either carries all three or none.
 * The CHECK below is what actually enforces that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_arrivals', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('notes');
            $table->foreignId('reversed_by')
                ->nullable()
                ->after('reversed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable()->after('reversed_by');
        });

        // Both or neither. A reversed arrival with no reason given is the exact shape this
        // feature exists to prevent — an inventory correction nobody has to account for.
        // `reversed_by` is deliberately outside the pair: it is `nullOnDelete`, so a departed
        // employee's row legitimately clears it years later, and the audit trail still names
        // them. A CHECK covering it would turn deleting a user into a constraint violation.
        DB::statement(<<<'SQL'
            ALTER TABLE stock_arrivals
            ADD CONSTRAINT stock_arrivals_reversal_is_whole CHECK (
                (reversed_at IS NULL AND reversal_reason IS NULL)
                OR (reversed_at IS NOT NULL AND reversal_reason IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_arrivals DROP CONSTRAINT IF EXISTS stock_arrivals_reversal_is_whole');

        Schema::table('stock_arrivals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};
