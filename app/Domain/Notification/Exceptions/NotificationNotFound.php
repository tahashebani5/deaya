<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * No such notification — for this person.
 *
 * **404 rather than 403, on purpose.** Somebody else's notification is not "forbidden", it is
 * none of this account's business, and answering 403 would confirm that the id names something
 * real. The same instinct that keeps every id out of the investor portal's paths.
 */
final class NotificationNotFound extends DomainException
{
    public static function make(): self
    {
        return new self('الإشعار غير موجود');
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
