<?php

use App\Domain\Order\Enums\DesignSource;
use App\Domain\Order\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A job of work: bags printed for a customer and got to them.
 *
 * **Money and addresses are snapshots, and that is the load-bearing decision here.** A city's
 * `delivery_price` is edited when the business renegotiates a rate, a product's price tier moves
 * when paper costs move, and a city can be deleted outright — none of which may rewrite what an
 * order said it cost on the day it was taken. So `city_name`, `region_name` and every money
 * column are copied in at the time and never read back from their source. The foreign keys stay
 * beside them for filtering and for opening the live record, but nothing displayed on an old
 * order is fetched through them. This also settles the note left on
 * `DeliveryService::deleteCity()`: an order naming a removed city keeps its address and its
 * price.
 *
 * **`fulfilment_type` is a snapshot too.** An administrator turning قرجي into a delivery city
 * next year must not retroactively change how last year's orders were handed over — and the
 * status those orders sit in was derived from this value at dispatch.
 *
 * **The timestamp columns are denormalised on purpose.** `order_status_transitions` is the
 * authority on what happened when; these exist so "everything printed this week" is an indexed
 * range scan rather than a walk through history. {@see OrderStatus::timestampColumn()} decides
 * which status stamps which, and answers null for `shortage` — a status bounced in and out of
 * cannot be honestly recorded in a single column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Plain sequential digits — '1', '2', '3' — unlike the customer's C1 and the
            // product's P7. Asked for that way: the number staff say on the phone should be the
            // number, with nothing to spell out. Allocated before insert from the table's own
            // sequence, so the column is NOT NULL from the first write and two concurrent
            // requests cannot collide. Stored rather than derived from `id` so the format can
            // gain a year or a branch later without the primary key having to change.
            $table->string('code', 20);

            // No cascade: a customer is deactivated, never deleted, so the row an order points
            // at is guaranteed to exist. The constraint is the floor under that promise.
            $table->foreignId('customer_id')->constrained('customers');

            // Which of their branches asked for it. Nullable — plenty of customers have one.
            $table->foreignId('customer_shop_id')->nullable()->constrained('customer_shops');

            $table->foreignId('city_id')->constrained('cities');
            $table->foreignId('region_id')->nullable()->constrained('regions');

            // The snapshot. Written once, never refreshed from the delivery map.
            $table->string('city_name', 100);
            $table->string('region_name', 100)->nullable();
            $table->string('fulfilment_type', 20);

            $table->string('status', 30)->default(OrderStatus::New->value);

            $table->string('design_source', 20)->default(DesignSource::None->value);

            // Who receives it, when that is not the customer themselves — a shop manager, a
            // relative, a driver. Null means "the customer", resolved for display rather than
            // copied, because a customer correcting their own phone number should fix it here
            // too.
            $table->string('recipient_name')->nullable();
            $table->string('recipient_phone', 20)->nullable();

            $table->text('address_details')->nullable();
            $table->text('notes')->nullable();

            // decimal, never float: these are summed, and a total built from binary floats
            // drifts a little further with every line. 12,2 holds far more than any real order
            // and matches the delivery price it is added to.
            $table->decimal('items_total', 12, 2)->default(0);
            $table->decimal('design_fee', 12, 2)->default(0);
            $table->decimal('delivery_price', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);

            // Free text: these belong to the shipping company's own vocabulary, which we
            // neither own nor validate — same reasoning as `cities.darb_branch`.
            $table->string('shipping_company')->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->string('courier_name')->nullable();

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('design_started_at')->nullable();
            $table->timestamp('printing_started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('cancellation_reason')->nullable();

            // Nullable so a seeded or imported order is possible; every order taken through the
            // API carries the signed-in user.
            $table->foreignId('created_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes()->index();

            // The two lists the app actually renders: one customer's orders, and the work
            // queue for a given status.
            $table->index(['customer_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('tracking_number');
        });

        // Partial, like every unique index in this schema: a removed order releases its code.
        // Validation gives the readable 422; this is what holds under two concurrent writes.
        DB::statement(
            'CREATE UNIQUE INDEX orders_code_unique ON orders (code) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
