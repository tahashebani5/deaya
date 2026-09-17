<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * An order was taken for somebody the shop has stopped selling to.
 *
 * Deactivation is the only "no longer a customer" this system has — there is no delete route,
 * because past orders must keep pointing at a row that still exists. That makes `is_active` the
 * whole of the rule, and it has to be enforced where the order is *made*: the FormRequest asks
 * only that the customer exist, and the app merely hides the button. A hidden button is a
 * suggestion, and a seeder, an import or a stale deep link never sees it.
 *
 * Turning somebody back on costs one tap and no explanation, which is what keeps this a refusal
 * rather than an obstacle.
 */
final class CustomerIsInactive extends DomainException
{
    public static function make(string $customerName): self
    {
        return new self("العميل «{$customerName}» معطَّل، ولا تُؤخذ منه طلبيات");
    }
}
