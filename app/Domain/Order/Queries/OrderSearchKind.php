<?php

declare(strict_types=1);

namespace App\Domain\Order\Queries;

/**
 * Which column one search box is actually asking about.
 *
 * An enum rather than a boolean pair, for the reason every status in this codebase is one: a
 * fifth way to search is a case here and a branch in the query, not a third flag that can be
 * true at the same time as the other two.
 */
enum OrderSearchKind: string
{
    /** `0912345678` — matched as a prefix, so a half-typed number still narrows the list. */
    case Phone = 'phone';

    /** `52` — matched exactly. «طلبية رقم ٥٢» means that one, not everything starting with 5. */
    case OrderCode = 'order_code';

    /** `C7` — matched exactly, and answers with every order that customer has placed. */
    case CustomerCode = 'customer_code';

    /** Anything else. Matched anywhere inside the customer's name. */
    case Name = 'name';
}
