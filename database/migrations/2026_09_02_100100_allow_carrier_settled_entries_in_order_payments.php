<?php

declare(strict_types=1);

use App\Domain\Order\Enums\OrderPaymentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Room in the ledger for an entry recording money the customer paid to somebody else.
 *
 * The shape rule was last restated when the write-off arrived, and it said: a reversal names the
 * row it undoes and no method; a write-off names neither; **everything else names a method**. A
 * carrier settlement is the second entry that moved no cash of ours, so it has no method to
 * name — and it would fall into that last branch and be refused by the CHECK.
 *
 * Restated as the three shapes that exist now, with the second one holding both of the entries
 * that close a debt without our cash moving:
 *
 * | type | `reverses_payment_id` | `method` |
 * | --- | --- | --- |
 * | `payment`, `refund` | null | required — cash, card, transfer |
 * | `reversal` | required — the row it undoes | null |
 * | `write_off`, `carrier_settled` | null | null |
 *
 * **Forward-only, as RULES.md §8 requires**: the previous migration still describes what it did
 * on the day it ran. The constraint is dropped and recreated under its own name rather than
 * edited, because PostgreSQL has no `ALTER CONSTRAINT` for a CHECK — and no data is touched:
 * every existing row is one of the three shapes the old rule allowed, and all three stay legal.
 */
return new class extends Migration
{
    public function up(): void
    {
        $reversal = OrderPaymentType::Reversal->value;
        $writeOff = OrderPaymentType::WriteOff->value;
        $carrier = OrderPaymentType::CarrierSettled->value;

        DB::statement('ALTER TABLE order_payments DROP CONSTRAINT order_payments_shape');

        DB::statement(<<<SQL
            ALTER TABLE order_payments
                ADD CONSTRAINT order_payments_shape CHECK (
                    (type = '{$reversal}' AND reverses_payment_id IS NOT NULL AND method IS NULL)
                    OR
                    (type IN ('{$writeOff}', '{$carrier}') AND reverses_payment_id IS NULL AND method IS NULL)
                    OR
                    (type NOT IN ('{$reversal}', '{$writeOff}', '{$carrier}') AND reverses_payment_id IS NULL AND method IS NOT NULL)
                )
        SQL);
    }

    public function down(): void
    {
        $reversal = OrderPaymentType::Reversal->value;
        $writeOff = OrderPaymentType::WriteOff->value;

        DB::statement('ALTER TABLE order_payments DROP CONSTRAINT order_payments_shape');

        // The rule exactly as it stood before. Rolling back over rows settled at the carrier
        // would fail here, which is the honest outcome: the shape they were written in is one
        // this constraint has no room for.
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
};
