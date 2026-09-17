<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exceptions;

use App\Domain\Identity\Exceptions\InvalidCredentials;
use App\Support\Exceptions\DomainException;

/**
 * The phone number and password given to the customer app do not match an account.
 *
 * **One failure for four different causes**, deliberately: no customer with that phone, a
 * customer who has never set a password (the ordinary state of everybody a clerk typed in), a
 * customer whose row is soft deleted, and a plain wrong password. Telling them apart turns the
 * login endpoint into a way of discovering which numbers in Libya are our customers, which is
 * worth more to a competitor than any single account.
 *
 * Its own class rather than {@see InvalidCredentials}: that one
 * reports against a `login` field, and the customer app posts `phone`. Same rule, different
 * field, and the field is what the app draws the error under.
 */
final class CustomerCredentialsAreWrong extends DomainException
{
    /** The request field the error is reported against. */
    private const FIELD = 'phone';

    public static function make(): self
    {
        return new self('رقم الهاتف أو كلمة المرور غير صحيحة');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return [self::FIELD => [$this->getMessage()]];
    }
}
