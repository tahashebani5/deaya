<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exceptions;

use App\Domain\Carrier\Exceptions\NawrisIsNotConfigured;
use App\Support\Exceptions\DomainException;

/**
 * Somebody tried to push through a Firebase project whose credentials were never filled in.
 *
 * Raised before any HTTP call, the same bargain {@see NawrisIsNotConfigured}
 * makes: sending an empty assertion and relaying Google's complaint about it turns a deployment
 * mistake into an error message that reads as though the notification were at fault.
 */
final class FcmIsNotConfigured extends DomainException
{
    public static function make(): self
    {
        return new self('لم تُضبط بيانات الإشعارات (Firebase) بعد');
    }
}
