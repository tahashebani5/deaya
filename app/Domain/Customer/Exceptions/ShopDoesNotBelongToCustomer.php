<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A shop id was supplied for a customer that does not own it.
 *
 * Over HTTP the FormRequest rejects this first, so this is the guard for every *other* caller
 * — a seeder, a console command, an import. Without it the sync would quietly create a
 * duplicate shop instead of refusing, which is the kind of silent data drift that is very
 * hard to trace back later.
 */
final class ShopDoesNotBelongToCustomer extends DomainException
{
    public static function make(int $shopId, int $customerId): self
    {
        return new self("المحل رقم {$shopId} لا ينتمي للعميل رقم {$customerId}");
    }
}
