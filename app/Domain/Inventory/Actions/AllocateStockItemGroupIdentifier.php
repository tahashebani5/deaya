<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\StockItemGroupIdentifier;
use Illuminate\Support\Facades\DB;

/**
 * Reserves the next group id and builds its code: G1, G2, G3 …
 *
 * The same reserve-first move as {@see AllocateStockItemIdentifier}: the code has to equal
 * 'G' + id, but the id only exists once the row is written, so the number is pulled from the
 * table's own sequence first and the insert carries a final, unique code.
 *
 * PostgreSQL-specific, like the three allocators it mirrors.
 */
final class AllocateStockItemGroupIdentifier
{
    /** Prefix that turns a numeric id into a group code. */
    public const PREFIX = 'G';

    public function __invoke(): StockItemGroupIdentifier
    {
        $id = (int) DB::scalar("select nextval(pg_get_serial_sequence('stock_item_groups', 'id'))");

        return new StockItemGroupIdentifier($id, self::PREFIX.$id);
    }
}
