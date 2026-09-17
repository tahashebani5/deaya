<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer left on the counter, and what became of it.
 *
 * **`undelivered_quantity` is the third subtrahend in the one place a quantity becomes money.**
 * `OrderItem::billableQuantity()` was `quantity − shortage_quantity`; it is now that less this
 * too, and every total in the application follows without a second rule being written anywhere.
 * In the line's own **pricing** unit, like the shortage beside it — what the customer counted,
 * not what a shelf holds. Null means «took it all»: «nothing recorded» is not «nothing left»,
 * exactly the distinction `shortage_quantity` already makes.
 *
 * **Nothing is subtracted in place, so nothing needs a reversing entry.** `quantity` stays what
 * the customer ordered forever — it is the question a partial delivery is the answer to — and
 * clearing this column returns the invoice to the number it was. The same reversibility
 * `SetOrderShortages` documents at length, for the same reason.
 *
 * **`undelivered_disposition` is a column rather than a derived answer, and that is the whole
 * decision this migration carries.** Where the leftover goes is `ProductionMode`: plain goods go
 * back on the shelf, printed and وسيط goods are a loss nobody else can buy. `OrderItem::isPrinted()`
 * answers that today — but it answers it *as the catalogue stands now*. File the product under a
 * different heading next year and a delivery from last March silently changes its story from
 * «عادت إلى المخزن» to «خسارة». That is precisely the retroactive rewrite `product_name` and
 * `variant_label` are copied onto the line to prevent: renaming a product must not rewrite an
 * invoice issued last year, and re-filing one must not rewrite a delivery either.
 *
 * It is also the difference between a screen that costs nothing and one that costs a query per
 * line: `isPrinted()` walks `product.productCategory.parent`, a chain nothing else on the order
 * list wants loaded, and strict mode turns a forgotten load into an exception rather than an
 * N+1 nobody notices.
 *
 * **The two are written together and cleared together.** A quantity with no disposition is not a
 * state the domain can reach — `RecordPartialDelivery` is the only writer — and the CHECK below
 * is the floor under that rather than a second opinion about it.
 *
 * No index on either: they are never filtered on. The orders list draws its chip from `items`,
 * which `OrderListQuery` already eager-loads, and the P&L reads `production_cost_entries`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Beside the shortage it works with, not at the end of the row: the two are read
            // together by `billableQuantity()` and by anybody reading the table by hand.
            $table->decimal('undelivered_quantity', 12, 3)->nullable()->after('shortage_quantity');
            $table->string('undelivered_disposition', 12)->nullable()->after('undelivered_quantity');
        });

        // Negative is meaningless and positive is not always required: zero is what an employee
        // who opened the box and changed their mind leaves behind, and it must read as «took it
        // all» rather than be refused at the database.
        DB::statement(<<<'SQL'
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_undelivered_quantity_not_negative
            CHECK (undelivered_quantity IS NULL OR undelivered_quantity >= 0)
        SQL);

        // The pair, enforced where it cannot be argued with. Not a substitute for
        // `RecordPartialDelivery` being the only writer — it is the proof that it is.
        DB::statement(<<<'SQL'
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_undelivered_disposition_travels_with_quantity
            CHECK ((undelivered_quantity IS NULL) = (undelivered_disposition IS NULL))
        SQL);

        // No backfill. Every line written before today was delivered whole as far as this
        // application knows, and null is exactly what says so — a zero would claim somebody
        // counted, and nobody did.
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_undelivered_disposition_travels_with_quantity');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_undelivered_quantity_not_negative');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['undelivered_disposition', 'undelivered_quantity']);
        });
    }
};
