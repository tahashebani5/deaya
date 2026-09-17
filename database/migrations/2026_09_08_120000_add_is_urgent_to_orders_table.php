<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «مستعجلة» — the one thing about an order's priority that is not its status.
 *
 * **A column, because it is a decision somebody makes.** The alternative considered was deriving
 * it from `placed_at` — every undelivered order past some age wearing the badge — and it answers
 * a different question: an order's age measures *our* delay, while this records *the customer's*
 * demand, and the two part company constantly. A rush job taken and delivered the same morning
 * never reaches any threshold, and an ordinary order stuck a fortnight waiting for the customer
 * to approve artwork is not urgent at all. See Docs/orders/ORDER-URGENT-AND-SORT.md.
 *
 * **NOT NULL with a default of false**, and no backfill needed as a result: every order already
 * in the table was taken without anybody asking for it to be rushed, and false is exactly what
 * that means. There is no third state — «لم يُسأل» and «ليست مستعجلة» are the same answer here.
 *
 * The index is **partial**, on the flag alone rather than the pair it is filtered with: the
 * urgent set is a small slice of a growing table, so `WHERE is_urgent` is what makes «المستعجلة
 * فقط» a lookup instead of a scan, and Postgres reads it alongside the ordering rather than
 * needing a composite for each sort direction. Live rows only, like every other index on this
 * schema — a soft-deleted order is not in any queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Beside the status, because it is read with it: the badge on a card sits on the
            // same band, and the two together are what the list is scanned for.
            $table->boolean('is_urgent')->default(false)->after('status');
        });

        DB::statement(
            'CREATE INDEX orders_urgent_index ON orders (placed_at) WHERE is_urgent AND deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_urgent_index');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_urgent');
        });
    }
};
