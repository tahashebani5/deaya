<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Somebody tried to stop the account they are signed in on.
 *
 * One tap from being locked out of the only screen that could undo it: stopping an account
 * revokes its tokens, so the request would succeed and the next one would be rejected. Refused
 * here rather than in the controller because it is a rule about accounts, not about HTTP.
 */
final class CannotStopOwnAccount extends DomainException
{
    public static function make(): self
    {
        return new self('لا يمكنك إيقاف حسابك أنت — اطلب من زميل أو من المدير');
    }
}
