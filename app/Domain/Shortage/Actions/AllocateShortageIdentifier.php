<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Actions;

use App\Domain\Shortage\DTOs\ShortageIdentifier;
use Illuminate\Support\Facades\DB;

/**
 * Reserves the next shortage id and builds its code: N1, N2, N3 …
 *
 * The same mechanism customers, products and orders use — `nextval` on the table's own sequence,
 * pulled *before* the insert so the row carries a final, unique code rather than a placeholder
 * two concurrent inserts could briefly share.
 *
 * **With a letter, unlike an order number.** `AllocateOrderIdentifier` drops the prefix because
 * an order number is said on its own — «طلبية رقم كام؟» — so a letter is a syllable to spell out
 * for no information. A shortage is the opposite: it is almost always said next to the order it
 * came off, and «نقص ٤ على طلبية ٤» is two bare fours in one sentence. N is for نقص.
 *
 * A rolled-back transaction leaves its number unused, so codes may skip one. They are
 * identifiers, not a count — the trade already accepted three times over in this schema.
 */
final class AllocateShortageIdentifier
{
    public function __invoke(): ShortageIdentifier
    {
        $id = (int) DB::scalar("select nextval(pg_get_serial_sequence('shortages', 'id'))");

        return new ShortageIdentifier($id, 'N'.$id);
    }
}
