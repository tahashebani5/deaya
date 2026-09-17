<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The supplied identifier and password do not match an account.
 *
 * Deliberately says nothing about *which* half was wrong — telling "no such account" apart
 * from "wrong password" would let someone discover who is registered.
 */
final class InvalidCredentials extends DomainException
{
    /** The request field the error is reported against. */
    private const FIELD = 'login';

    public static function make(): self
    {
        return new self('بيانات الدخول غير صحيحة');
    }

    /**
     * Reported against the `login` field so the client can show it inline, exactly like a
     * validation error.
     *
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return [self::FIELD => [$this->getMessage()]];
    }
}
