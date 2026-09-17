<?php

declare(strict_types=1);

use App\Domain\Order\Enums\OrderPaymentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Room in the ledger for an entry that closes a debt without any money moving.
 *
 * The table's shape rule was written when there were three kinds of row, and it said: a reversal
 * names the row it undoes and no method; **everything else names a method**. A write-off is the
 * first entry that is neither — it undoes nothing, and it has no method because no cash changed
 * hands — so the old CHECK would have refused every one of them.
 *
 * Restated here as the three shapes that actually exist, keyed off the type rather than off
 * "reversal or not":
 *
 * | type | `reverses_payment_id` | `method` |
 * | --- | --- | --- |
 * | `payment`, `refund` | null | required — cash, card, transfer |
 * | `reversal` | required — the row it undoes | null |
 * | `write_off` | null | null |
 *
 * **Forward-only, as RULES.md §8 requires**: the original migration still describes what it did
 * on the day it ran, and this one describes the change. The constraint is dropped and recreated
 * under its own name rather than edited, because PostgreSQL has no `ALTER CONSTRAINT` for a
 * CHECK — and no data is touched: every existing row is one of the two shapes the old rule
 * allowed, and both stay legal here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $reversal = OrderPaymentType::Reversal->value;
        $writeOff = OrderPaymentType::WriteOff->value;

        DB::statement('ALTER TABLE order_payments DROP CONSTRAINT order_payments_shape');

        DB::statement(<<<SQL
            ALTER TABLE order_payments
                ADD CONSTRAINT order_payments_shape CHECK (
                    (type = '{$reversal}' AND reverses_payment_id IS NOT NULL AND method IS NULL)
                    OR
                    (type = '{$writeOff}' AND reverses_payment_id IS NULL AND method IS NULL)
                    OR
                    (type NOT IN ('{$reversal}', '{$writeOff}') AND reverses_payment_id IS NULL AND method IS NOT NULL)
                )
        SQL);
    }

    public function down(): void
    {
        $reversal = OrderPaymentType::Reversal->value;

        DB::statement('ALTER TABLE order_payments DROP CONSTRAINT order_payments_shape');

        // The rule exactly as it stood before. Rolling back over rows that were written off
        // would fail here, which is the honest outcome: the shape they were written in is one
        // this constraint has no room for.
        DB::statement(<<<SQL
            ALTER TABLE order_payments
                ADD CONSTRAINT order_payments_shape CHECK (
                    (type = '{$reversal}' AND reverses_payment_id IS NOT NULL AND method IS NULL)
                    OR
                    (type <> '{$reversal}' AND reverses_payment_id IS NULL AND method IS NOT NULL)
                )
        SQL);
    }
};
