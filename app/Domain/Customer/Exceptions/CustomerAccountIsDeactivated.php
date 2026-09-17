<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exceptions;

use App\Domain\Identity\AuthService;
use App\Support\Exceptions\DomainException;

/**
 * The credentials were right, and the shop has stopped selling to this customer.
 *
 * **Thrown after the password check, never before** — the same order
 * {@see AuthService::login()} follows and for the same reason. Answering
 * «حسابك موقوف» to a wrong password would let somebody enumerate accounts, which is exactly what
 * {@see CustomerCredentialsAreWrong} exists to prevent. Past the password the caller has already
 * proved the account is theirs, so they may be told why they cannot come in.
 *
 * 403 rather than 422: this is not a field the app can correct by asking the user to retype it.
 * Distinct from {@see CustomerIsInactive}, which refuses an *order* from a deactivated customer —
 * that one is thrown at staff taking the order, this one at the customer trying to sign in.
 */
final class CustomerAccountIsDeactivated extends DomainException
{
    public static function make(): self
    {
        return new self('حسابك موقوف حالياً. تواصل معنا لإعادة تفعيله');
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
