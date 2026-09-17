<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTOs;

use App\Domain\Customer\Models\Customer;

/**
 * The outcome of a successful customer sign-in: who came in, and the token they must send back.
 * Typed so the service→controller boundary carries no arrays.
 */
final readonly class CustomerAuthResult
{
    public function __construct(
        public Customer $customer,
        public string $token,
    ) {}
}
