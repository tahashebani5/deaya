<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Customer\Actions\AllocateCustomerIdentifier;
use Illuminate\Support\Facades\DB;

/**
 * Reserves the next employee code: 1001, 1002, 1003 …
 *
 * A short number an employee can read out loud — "الطلبية عند 1004" — which is why it is not
 * the user id: ids are an implementation detail that starts at 1 and is shared with every other
 * table's ids in conversation. A code that starts at 1001 is never mistaken for a row number,
 * an order number or a quantity.
 *
 * Its own sequence rather than {@see AllocateCustomerIdentifier}'s reserve-the-id trick, because
 * nothing here needs the id before the insert: the code stands alone, so it can simply be drawn
 * from a counter of its own. `nextval` never returns the same number twice, even under
 * concurrency, so two accounts created in the same instant cannot collide.
 *
 * A rolled-back transaction leaves its number unused, so codes may skip. They are identifiers,
 * not a count of employees — and a code is never reused after the account that held it is gone.
 *
 * PostgreSQL-specific, like the customer sequence it sits beside.
 */
final class AllocateEmployeeCode
{
    /** Kept in step with the sequence created in the migration that added the column. */
    public const SEQUENCE = 'users_employee_code_seq';

    public function __invoke(): string
    {
        return (string) DB::scalar('select nextval(?)', [self::SEQUENCE]);
    }
}
