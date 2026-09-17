<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The identifier and password are right, and the account has been stopped.
 *
 * **Said plainly, unlike {@see InvalidCredentials}.** That one is deliberately vague because
 * telling «no such account» from «wrong password» would let a stranger discover who is
 * registered. Nothing is leaked here: reaching this message costs the correct password, so the
 * person reading it is the account holder — and «كلمة المرور غير صحيحة» would send them to
 * reset a password that works fine, instead of to the manager who stopped their account.
 */
final class AccountDeactivated extends DomainException
{
    /** The request field the error is reported against, as InvalidCredentials does. */
    private const FIELD = 'login';

    public static function make(): self
    {
        return new self('هذا الحساب موقوف — راجع المدير');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return [self::FIELD => [$this->getMessage()]];
    }
}
