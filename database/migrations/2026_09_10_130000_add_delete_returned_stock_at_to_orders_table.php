<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a delete did to the warehouse, so that a restore can be an exact undo of it.
 *
 * `DeleteOrder` gives the goods back only for the lines that still had stock drawn — read from
 * the ledger, because an order cancelled before it was deleted has already had its draw
 * credited. `RestoreOrder` therefore cannot re-deduct on «هل خرج مخزون من هذه الطلبية يوماً؟»:
 * that question is `orders.stock_deducted_at`, it is never cleared by anything, and a restore
 * built on it would take 300 bags off the shelf for an order whose delete put nothing back.
 *
 * Recomputing the answer at restore time is the alternative, and it is the one that loses.
 * «هل لهذه الطلبية عكسٌ حيٌّ باسمها؟» is true immediately after *any* reversal — a cancellation's
 * as much as a delete's — so a cancelled-then-deleted order would look identical to a
 * deleted-only one, which is precisely the pair this column exists to tell apart. What the
 * delete actually did is a fact about a moment, and facts are stamped.
 *
 * Nullable, and cleared by the restore rather than left standing: unlike `stock_deducted_at`,
 * which remembers history, this describes what the archive is currently holding, and an order
 * back in the lists carrying «حذفُها أعاد المخزون» would offer a second undo of a thing already
 * undone. That is the same reasoning `ReinstateCancelledOrder` applies to `cancelled_at`.
 *
 * No index: it is never a filter, only ever read off the one row already in hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('delete_returned_stock_at')->nullable()->after('stock_deducted_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delete_returned_stock_at');
        });
    }
};
