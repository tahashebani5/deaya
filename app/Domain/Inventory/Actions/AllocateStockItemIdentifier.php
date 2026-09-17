<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Actions\AllocateProductIdentifier;
use App\Domain\Inventory\DTOs\StockItemIdentifier;
use Illuminate\Support\Facades\DB;

/**
 * Reserves the next stock item id and builds its code: S1, S2, S3 …
 *
 * The same reserve-first move as {@see AllocateProductIdentifier}, and for the same reason: the
 * code has to equal 'S' + id, but the id only exists once the row is written. Pulling the next
 * value from the table's own sequence first means the insert carries a final, unique code —
 * `nextval` never returns the same number twice, even under concurrency.
 *
 * A rolled-back transaction leaves its number unused, so codes follow the ids and may skip. They
 * are identifiers, not a count of shelves.
 *
 * PostgreSQL-specific, like the two allocators it mirrors.
 */
final class AllocateStockItemIdentifier
{
    /** Prefix that turns a numeric id into a stock item code. */
    public const PREFIX = 'S';

    public function __invoke(): StockItemIdentifier
    {
        $id = (int) DB::scalar("select nextval(pg_get_serial_sequence('stock_items', 'id'))");

        return new StockItemIdentifier($id, self::PREFIX.$id);
    }
}
