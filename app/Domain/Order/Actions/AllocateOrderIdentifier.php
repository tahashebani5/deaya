<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\DTOs\OrderIdentifier;
use Illuminate\Support\Facades\DB;

/**
 * Reserves the next order id and builds its number: 1200, 1201, 1202 …
 *
 * Where the count *appears* to begin is the business's choice, not this action's — the sequence
 * was wound forward to 1200 by `start_order_numbering_at_1200`, and nothing here knows or cares
 * what number comes back.
 *
 * The same mechanism customers and products use, with one difference: **no letter prefix.** A
 * customer is C7 and a product is P7 because those codes are said out loud alongside each other
 * and the letter tells you which list to look in. An order number is said on its own — «طلبية
 * رقم كام؟» — so a prefix is a syllable to spell out for no information.
 *
 * Why reserve the id instead of numbering after the insert? The number has to equal the id, but
 * the id only exists once the row is written. Writing first and filling the code in afterwards
 * would need the column nullable, and two concurrent inserts would briefly share a placeholder.
 * Pulling the next value from the table's own sequence first means the insert carries a final,
 * unique number — `nextval` never returns the same value twice, even under concurrency.
 *
 * A rolled-back transaction leaves its number unused, so order numbers may skip one. They are
 * identifiers, not a count — the same trade already accepted for customers and products.
 *
 * Stored rather than derived from `id` so the format can gain a year or a branch later without
 * the primary key having to change. PostgreSQL-specific, like its two siblings.
 */
final class AllocateOrderIdentifier
{
    public function __invoke(): OrderIdentifier
    {
        $id = (int) DB::scalar("select nextval(pg_get_serial_sequence('orders', 'id'))");

        return new OrderIdentifier($id, (string) $id);
    }
}
