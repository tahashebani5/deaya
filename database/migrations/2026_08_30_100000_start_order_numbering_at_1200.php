<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Order numbers start at 1200.
 *
 * A workshop that has been printing for years does not hand a customer «طلبية رقم ٣». The number
 * is said out loud — to a customer on the phone, to a driver at the door — and starting it at 1
 * tells everyone who hears it exactly how many orders the shop has ever taken. 1200 is the
 * business's choice of where the count *appears* to begin; nothing downstream reads meaning into
 * the digits.
 *
 * **The id moves with the code, because they are the same number.** {@see AllocateOrderIdentifier}
 * draws the code from the `orders` id sequence precisely so an order has one identifier and not
 * two, and `OrderTest` asserts the two are equal. Renumbering only `code` would leave every
 * existing order answering to 1200 in conversation and to 6 in its URL — the second identifier
 * that design exists to prevent. So the key moves, its five children follow, and the sequence is
 * wound forward so the next order continues from where these leave off.
 *
 * **Order is preserved, not invented.** Rows are numbered by ascending id, so the order taken
 * first keeps the lowest number.
 *
 * **What follows the key by hand.** `stock_movements.reference_id` and `activity_log.subject_id`
 * both name an order without a foreign key — the ledger points at orders it must not be deleted
 * with, and the audit trail is polymorphic. Neither can be cascaded to, so both are moved here
 * explicitly. `order_payments.receipt_path` is deliberately *not* touched: the stored path is
 * the file's address, not a formula recomputed from the order id, so a receipt filed under
 * `payment-receipts/7/` keeps resolving and only looks unlike its order.
 *
 * The five foreign keys are made DEFERRABLE for the length of the transaction and put back the
 * way they were: a parent whose key is moving is briefly unmatched by its children, and deferring
 * is what lets one statement follow another instead of both having to be true at once.
 *
 * On a database with no orders this is only the `setval` — the first order ever taken is 1200.
 */
return new class extends Migration
{
    /**
     * The number the first order answers to from here on.
     */
    private const FIRST_NUMBER = 1200;

    /**
     * Where it started, and where `down()` puts it back.
     */
    private const ORIGINAL_FIRST_NUMBER = 1;

    /**
     * Every foreign key that names an order, by the table holding it.
     *
     * @var array<string, string>
     */
    private const CHILD_KEYS = [
        'order_items' => 'order_items_order_id_foreign',
        'order_designs' => 'order_designs_order_id_foreign',
        'order_status_transitions' => 'order_status_transitions_order_id_foreign',
        'order_payments' => 'order_payments_order_id_foreign',
        'production_cost_entries' => 'production_cost_entries_order_id_foreign',
    ];

    public function up(): void
    {
        $this->renumberFrom(self::FIRST_NUMBER);
    }

    /**
     * Forward-only is the rule, and this obeys it by being a renumbering in the other direction
     * rather than an undo: the same mechanism, aimed back at 1. Nothing is restored from a
     * record of what the numbers used to be, because the mapping is the row order itself.
     */
    public function down(): void
    {
        $this->renumberFrom(self::ORIGINAL_FIRST_NUMBER);
    }

    /**
     * Renumbers every order — soft deleted ones included — so the oldest becomes `$firstNumber`.
     *
     * Refuses to run if any order already sits at or above the target, because the mapping only
     * works while old and new numbers cannot collide: an order moving onto a number another
     * order still holds would break the primary key mid-statement. Re-running is therefore safe
     * and does nothing but keep the sequence honest.
     */
    private function renumberFrom(int $firstNumber): void
    {
        DB::transaction(function () use ($firstNumber): void {
            $highest = (int) DB::scalar('select coalesce(max(id), 0) from orders');

            if ($highest < $firstNumber) {
                $this->moveEveryOrderTo($firstNumber);
            }

            $this->windSequencePast($firstNumber);
        });
    }

    private function moveEveryOrderTo(int $firstNumber): void
    {
        // Taken before anything is read, because this runs against a shop that is still taking
        // orders. The mapping below is a snapshot, and an order committed after it was taken
        // would keep its old number while every other order moved — held here until the
        // transaction ends, which is a few milliseconds on tables this size.
        DB::statement('LOCK TABLE orders IN ACCESS EXCLUSIVE MODE');

        foreach (self::CHILD_KEYS as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} ALTER CONSTRAINT {$constraint} DEFERRABLE INITIALLY DEFERRED");
        }

        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        // The mapping, decided once and read by every statement below, so the parent and its
        // children cannot disagree about where a given order went.
        // The number is interpolated rather than bound: PostgreSQL cannot infer the type of a
        // parameter inside `CREATE TABLE AS`, and this one is a private integer constant, never
        // anything a request supplies.
        DB::statement(
            'CREATE TEMPORARY TABLE order_renumbering ON COMMIT DROP AS
             SELECT id AS old_id, ('.($firstNumber - 1).' + row_number() OVER (ORDER BY id))::bigint AS new_id
             FROM orders'
        );

        // `code` is written from the same value rather than copied from the old one: the two are
        // one number, and this is the migration that would otherwise let them drift apart.
        DB::statement(
            'UPDATE orders o SET id = m.new_id, code = m.new_id::text
             FROM order_renumbering m WHERE o.id = m.old_id'
        );

        foreach (array_keys(self::CHILD_KEYS) as $table) {
            DB::statement(
                "UPDATE {$table} c SET order_id = m.new_id
                 FROM order_renumbering m WHERE c.order_id = m.old_id"
            );
        }

        // The ledger. Scoped to the two movement types that reference an order, because
        // `reference_id` means something different for every other type — a stock arrival, say —
        // and those numbers must not be moved.
        DB::statement(
            "UPDATE stock_movements s SET reference_id = m.new_id
             FROM order_renumbering m
             WHERE s.reference_id = m.old_id
               AND s.movement_type IN ('order_fulfillment', 'order_reversal')"
        );

        // The audit trail. Only the order's own entries: its items, payments and transitions
        // keep their own keys, which this migration does not move.
        DB::statement(
            "UPDATE activity_log a SET subject_id = m.new_id
             FROM order_renumbering m
             WHERE a.subject_type = 'order' AND a.subject_id = m.old_id"
        );

        // Checks the deferred keys now, inside the transaction, so a mistake here rolls back as
        // a failed migration rather than surfacing later as an orphan.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        foreach (self::CHILD_KEYS as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} ALTER CONSTRAINT {$constraint} NOT DEFERRABLE");
        }
    }

    /**
     * Points the sequence at the number the next order should get.
     *
     * `is_called = false` so `nextval` returns that number itself rather than the one after it —
     * which is what makes the very first order on an empty database 1200 and not 1201.
     */
    private function windSequencePast(int $firstNumber): void
    {
        $next = max(
            $firstNumber,
            ((int) DB::scalar('select coalesce(max(id), 0) from orders')) + 1,
        );

        DB::statement(
            "select setval(pg_get_serial_sequence('orders', 'id'), ?::bigint, false)",
            [$next]
        );
    }
};
