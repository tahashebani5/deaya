<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Nothing more may be recorded against a shortage that is already fully supplied.
 *
 * The door back is a reversal of whichever supply closed it, which re-runs the totals and puts
 * the status wherever the remaining quantity says it belongs — not a second purchase piled on
 * top, which would put money against a requirement that no longer exists.
 */
final class ShortageIsClosed extends DomainException
{
    public static function make(): self
    {
        return new self('النقص مكتمل — لا يمكن تسجيل توفير جديد عليه');
    }
}
