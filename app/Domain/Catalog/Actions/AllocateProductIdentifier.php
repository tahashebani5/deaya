<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\DTOs\ProductIdentifier;
use App\Domain\Customer\Actions\AllocateCustomerIdentifier;
use Illuminate\Support\Facades\DB;

/**
 * Reserves the next product id and builds its code: P1, P2, P3 …
 *
 * The same reserve-first move as {@see AllocateCustomerIdentifier}, and for the same reason: the
 * code has to equal 'P' + id, but the id only exists once the row is written. Writing first and
 * updating afterwards would need the column to be nullable, and two concurrent inserts would
 * briefly share a placeholder. Pulling the next value from the table's own sequence first means
 * the insert carries a final, unique code — `nextval` never returns the same number twice, even
 * under concurrency.
 *
 * A rolled-back transaction leaves its number unused, exactly as it would for the id itself, so
 * codes follow the ids and may skip. They are identifiers, not a count of products.
 *
 * PostgreSQL-specific, like the customer allocator it mirrors.
 */
final class AllocateProductIdentifier
{
    /** Prefix that turns a numeric id into a product code. */
    public const PREFIX = 'P';

    public function __invoke(): ProductIdentifier
    {
        $id = (int) DB::scalar("select nextval(pg_get_serial_sequence('products', 'id'))");

        return new ProductIdentifier($id, self::PREFIX.$id);
    }
}
