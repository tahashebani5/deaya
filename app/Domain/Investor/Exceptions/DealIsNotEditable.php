<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The terms close when a deal opens.
 *
 * Its shape, its investors and their percentages are what the money will be split by, so
 * rewriting them after goods have arrived rewrites who earned what. Renegotiating is a new deal.
 */
final class DealIsNotEditable extends DomainException
{
    public static function make(string $code): self
    {
        return new self("الصفقة {$code} فُتحت، ولم تعد شروطها قابلة للتعديل");
    }
}
