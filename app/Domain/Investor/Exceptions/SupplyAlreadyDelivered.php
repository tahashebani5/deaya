<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A funding claim is revocable only while nothing has arrived on it.
 */
final class SupplyAlreadyDelivered extends DomainException
{
    public static function make(): self
    {
        return new self('وصلت بضاعة على هذا الإقرار، فلم يعد يُلغى');
    }
}
