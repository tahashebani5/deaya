<?php

declare(strict_types=1);

use App\Domain\Shortage\Enums\ShortageSource;
use App\Domain\Shortage\Enums\ShortageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One thing the shop is short of, and the chase to get it.
 *
 * **Not a second copy of `order_items.shortage_quantity`.** That column is the invoice — see
 * `OrderItem::billableQuantity()`, which subtracts it from what the customer is billed — and it
 * keeps its single writer, `SetOrderShortages`. This table answers the questions that column
 * cannot: who is chasing it, what it cost to get, and what happened to it after the order moved
 * on. See Docs/shortages/SHORTAGES-DESIGN.md §١.
 *
 * **The reconciliation, and why `required_quantity` is stored rather than read through:**
 *
 *     required_quantity = order_item.shortage_quantity + Σ supplied_quantity
 *
 * Recording a supply writes back to the order line, so a `required` read straight off that line
 * would shrink every time somebody filled part of the gap, and «المتبقي» would be zero forever —
 * partial supply, the case §٥ of the brief is mostly about, would be invisible. Adding back what
 * has been supplied pins the number at what was originally missing and makes the sync idempotent:
 * running it twice changes nothing, which matters because a supply writes to the order, the write
 * fires the event, and the event runs the sync.
 *
 * **`supplied_quantity` and `total_paid` are caches with one writer**, `RecalculateShortageTotals`
 * — the `orders.paid_amount` arrangement, for the same reason: the ledger beside them is the
 * truth and a test asserts they agree on every path. **The remaining quantity is not stored at
 * all.** It is `required - supplied`, derived in the Resource; a third number in a row of two is
 * the third thing that can disagree with the other two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shortages', function (Blueprint $table) {
            $table->id();

            /*
             * «نقص رقم كام؟» — N7, allocated from this table's own sequence the way a customer
             * gets C7 and a product P7.
             *
             * **With a letter, unlike an order number.** An order number is said on its own so a
             * prefix is a syllable for nothing; a shortage is said next to the order it came from
             * — «نقص ٤ على طلبية ٤» — and two bare fours in one sentence is the ambiguity the
             * letter exists to remove.
             */
            $table->string('code', 20);

            $table->string('source', 20)->default(ShortageSource::Manual->value);

            /*
             * The order this came off, and the line inside it.
             *
             * **No cascade on either**, and the line is `nullOnDelete` rather than followed: a
             * shortage that has money against it outlives the line it came from — see the partial
             * unique index below and `SyncShortagesFromOrder`. The order itself is soft-deleted
             * now (Docs/orders/ORDER-DELETE-AND-ARCHIVE.md), so the constraint never fires there
             * either; it is declared for the shape, not for the sweep.
             */
            $table->foreignId('order_id')->nullable()->constrained('orders');
            $table->foreignId('order_item_id')->nullable()
                ->constrained('order_items')->nullOnDelete();

            // Copied at creation so the shortages screen can filter and group by customer without
            // joining through an order that may since have been archived.
            $table->foreignId('customer_id')->nullable()->constrained('customers');

            /*
             * What is short. **Both nullable, and `name` is not** — a manual shortage is very
             * often something the catalogue has never heard of, and forcing a product link there
             * makes an employee pick the nearest wrong row to get past the field.
             */
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants');

            // The snapshot, for the reason `order_items.product_name` is one: a product gets
            // renamed, and a closed shortage is a record of what was chased, not of what that row
            // is called today.
            $table->string('name');

            // piece | kilogram — `PricingUnit`, the same vocabulary the line and the shelf use.
            $table->string('unit', 20);

            // Three decimals, like every quantity in this schema: a per-kilo product is short by
            // a fraction of one.
            $table->decimal('required_quantity', 12, 3);

            // Caches. See the class docblock — `RecalculateShortageTotals` is the only writer.
            $table->decimal('supplied_quantity', 12, 3)->default(0);
            $table->decimal('total_paid', 14, 2)->default(0);

            $table->string('status', 20)->default(ShortageStatus::New->value);

            /*
             * Who is chasing it. Null is «غير مُسنَد», which is a real and common state — a
             * shortage generated at two in the morning by an order moving to «نواقص» belongs to
             * nobody until somebody picks it up.
             *
             * `nullOnDelete` on both: an employee leaves, and the shortage they were chasing is
             * still a shortage. Losing the row to keep the attribution would be the wrong trade.
             */
            $table->foreignId('assigned_to_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            // The list's own order: newest first, filtered by status. Both screens read this way.
            $table->index(['status', 'id']);

            // «ما المُسنَد إليّ ولم يُغلق؟» — the employee's own queue, which is a filter rather
            // than a screen and so shares the list's index shape.
            $table->index(['assigned_to_user_id', 'status']);

            // "Everything this order is short of", the link from the order screen.
            $table->index('order_id');

            // "How often are we short of this product" — the report this shape makes possible.
            $table->index(['product_id', 'status']);
        });

        // Partial, like every unique index in this schema: a removed shortage releases its code.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX shortages_code_unique
                ON shortages (code)
                WHERE deleted_at IS NULL
        SQL);

        /*
         * **The answer to «منع تكرار النواقص الناتجة عن نفس بند الطلب».**
         *
         * One live shortage per order line, ever. `SyncShortagesFromOrder` updates rather than
         * inserts and so never trips this — which is exactly why it is here: a guard that only
         * the careful path respects is a guard that the second path, written a year from now by
         * somebody who did not read this file, walks straight past. An order moved to «نواقص»
         * twice must not produce two rows, and a check in PHP loses to two concurrent requests
         * that both pass it before either commits.
         *
         * Partial on both columns: a manual shortage has no line and must not compete for the
         * single NULL slot, and a soft-deleted row must not hold a slot it no longer uses.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX shortages_order_item_id_unique
                ON shortages (order_item_id)
                WHERE order_item_id IS NOT NULL AND deleted_at IS NULL
        SQL);

        // A shortage of nothing is not a shortage. Guarded here as well as in validation, so no
        // console command or importer can write one — RULES.md §8.
        DB::statement(<<<'SQL'
            ALTER TABLE shortages
                ADD CONSTRAINT shortages_required_quantity_positive CHECK (required_quantity > 0)
        SQL);

        // The caches may not go backwards past zero. They are written by one action, which is
        // where the arithmetic lives; this is the floor under it.
        DB::statement(<<<'SQL'
            ALTER TABLE shortages
                ADD CONSTRAINT shortages_totals_not_negative CHECK (
                    supplied_quantity >= 0 AND total_paid >= 0
                )
        SQL);

        /*
         * **An order-born shortage names its order; a manual one names none.**
         *
         * The line may be null on either — a manual shortage never had one, and an order-born
         * shortage keeps its money after its line is deleted off the order (`nullOnDelete`
         * above). What may never be null on an order-born row is the order itself, because
         * `ShortageListQuery` decides whether a reader may see the row by looking at whether that
         * order is archived. A row that lost its `order_id` would be a shortage about a deleted
         * order that every reader can see — the back door Docs §٥ exists to close.
         */
        $fromOrder = ShortageSource::FromOrder->value;

        DB::statement(<<<SQL
            ALTER TABLE shortages
                ADD CONSTRAINT shortages_source_shape CHECK (
                    (source = '{$fromOrder}' AND order_id IS NOT NULL)
                    OR
                    (source <> '{$fromOrder}' AND order_item_id IS NULL)
                )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('shortages');
    }
};
