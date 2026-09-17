<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\CustomerIdentifier;
use Database\Seeders\CustomerSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Reserves the next customer id and builds its code: A1, A2, A3 …
 *
 * **The letter is 'A' because the customer book already used it.** The 667-row sheet the
 * business ran on before this system — ارقام الزباين.xlsx — numbered its customers A1..A667,
 * and those codes are what staff say on the phone and write on a bag. Importing that book
 * under a different letter would have renamed 600 customers on their first day, so the
 * allocator adopted the business's own scheme rather than the other way round.
 * {@see CustomerSeeder} seeds each row under its sheet id, which is what
 * keeps `code` = 'A'.`id` true for the imported customers as well as the ones created since.
 *
 * Why reserve the id instead of computing the code after inserting?
 *
 * The code has to equal 'A' + id, but the id only exists once the row is written. Writing
 * first and updating the code afterwards would need the column to be nullable, and two
 * concurrent inserts would briefly share a placeholder value. Pulling the next value from
 * the table's own sequence first means the insert carries a final, unique code, so the
 * column stays NOT NULL and no two requests can ever collide — `nextval` never returns the
 * same number twice, even under concurrency.
 *
 * A rolled-back transaction leaves its number unused, exactly as it would for the id
 * itself, so codes follow the ids and may skip a number. They are identifiers, not a count.
 *
 * PostgreSQL-specific: this is the only place in the codebase that depends on the driver.
 */
final class AllocateCustomerIdentifier
{
    /** Prefix that turns a numeric id into a customer code. */
    public const PREFIX = 'A';

    public function __invoke(): CustomerIdentifier
    {
        $id = (int) DB::scalar("select nextval(pg_get_serial_sequence('customers', 'id'))");

        return new CustomerIdentifier($id, self::PREFIX.$id);
    }
}
