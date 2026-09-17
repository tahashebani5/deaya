<?php

use App\Domain\Order\Enums\OrderDesignStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The artwork conversation for one order, one version per row.
 *
 * «لم يعجبه التصميم، حط التالي» is a list, not a set of order statuses. The order stays
 * «قيد التصميم» while versions come and go, and each row records what was proposed and — when
 * it was turned down — why. Modelling it as statuses would have cost three more cases on the
 * state machine to express one conversation, and still could not answer "how many versions did
 * this take?".
 *
 * **No file columns, and no copy of the file.** The row points at `customer_designs`, which
 * already holds the disk, the path and the checksum. The instinct is to snapshot the file the
 * way `order_items` snapshots a price — but the two are not alike. A price is *edited* in place
 * when the business changes it; a design is not. `customer_designs` never deletes a file (that
 * is the stated commitment of the table, so an order printed last year can still show what was
 * printed) and a different file is a different row by force of its checksum index. So the thing
 * a snapshot would protect against cannot happen, and copying would duplicate print files of up
 * to 25 MB for nothing.
 *
 * **Every design comes through the customer's library, including our own work.** The artwork is
 * the customer's property and belongs on their record rather than buried inside one order.
 * Whether *we* drew it — and may therefore charge for it — is `orders.design_source`, which is a
 * different question and the only one that touches money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_designs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // No cascade: a design row is never really deleted, precisely so this reference
            // cannot dangle.
            $table->foreignId('customer_design_id')->constrained('customer_designs');

            // 1, 2, 3 … within the order. Allocated by the action rather than counted at read
            // time, so a removed version does not renumber the ones after it and "التصميم
            // الثالث" keeps meaning the same file it meant in the conversation.
            $table->unsignedSmallInteger('version');

            $table->string('status', 20)->default(OrderDesignStatus::Proposed->value);

            // Required when the status is `rejected`; that rule lives in the action, because
            // the database cannot express "required only for one value" without a check
            // constraint that would then also have to know about every future status.
            $table->text('rejection_reason')->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['order_id', 'version']);
            $table->index('status');
        });

        // Two rows claiming to be version 3 of the same order is a bug, not a decision.
        // Partial, like every unique index here.
        DB::statement(
            'CREATE UNIQUE INDEX order_designs_unique_version_per_order
             ON order_designs (order_id, version) WHERE deleted_at IS NULL'
        );

        // At most one approved design per order. This is what «قيد الطباعة» checks for, and two
        // approved versions would make "which one do we print?" unanswerable on the shop floor.
        DB::statement(
            "CREATE UNIQUE INDEX order_designs_one_approved_per_order
             ON order_designs (order_id) WHERE status = 'approved' AND deleted_at IS NULL"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('order_designs');
    }
};
