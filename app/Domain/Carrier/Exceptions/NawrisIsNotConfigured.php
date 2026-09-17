<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Somebody tried to dispatch through a carrier whose credentials were never filled in.
 *
 * Raised before any HTTP call. The alternative — sending an empty `authentication_key` and
 * relaying whatever they say about it — turns a deployment mistake into a carrier error message
 * that reads as though the parcel were at fault.
 */
final class NawrisIsNotConfigured extends DomainException
{
    public static function make(): self
    {
        return new self('لم تُضبط بيانات الاتصال بشركة نورس بعد');
    }
}
