<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which orders are in a parcel.
 *
 * **One row per order today, and that is not an argument against the table.** We ship one order
 * per parcel; the carrier's own model is a parcel that may hold several. Keeping the join
 * explicit is what makes consolidation a feature rather than a migration through live parcel
 * data, and it makes "rebuild the whole parcel" a single query instead of a rule somebody has to
 * remember.
 *
 * **The unique key is `(parcel_id, order_id)`, deliberately not `order_id` alone.** The system
 * this was compiled from keyed on the order, which meant an order could never appear in a second
 * parcel and re-dispatching required deleting the link row — taking the dispatch history with it.
 * Here an order can be in several parcels over its life, and "at most one *open* parcel per
 * order" is enforced in code against `nawris_parcels.closed_at`, which keeps the history and
 * still gives the guarantee.
 *
 * `amount_to_collect` is this order's share of the parcel's COD, as sent. With one order per
 * parcel it equals the parcel's own figure — it exists because splitting a consolidated
 * collection back across orders is exactly what settlement would eventually need, and that is
 * unrecoverable if it was never recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nawris_parcel_orders', function (Blueprint $table) {
            $table->id();

            // Indexed for the join that rebuilds a parcel from any one of its orders.
            $table->foreignId('nawris_parcel_id')->constrained('nawris_parcels')->cascadeOnDelete();

            // No cascade: an order is never hard-deleted, so the row it points at is guaranteed
            // to exist, and the dispatch history must survive anything that happens to the order.
            $table->foreignId('order_id')->constrained('orders');

            $table->decimal('amount_to_collect', 12, 2);

            $table->timestamps();
            $table->softDeletes()->index();

            // "Which parcels has this order been in?" — the history query.
            $table->index('order_id');
        });

        // Partial, so a soft-deleted link does not block the same order being put back into the
        // same parcel. The open-parcel rule is enforced in code; this is the floor under it.
        DB::statement(
            'CREATE UNIQUE INDEX nawris_parcel_orders_unique ON nawris_parcel_orders (nawris_parcel_id, order_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('nawris_parcel_orders');
    }
};
