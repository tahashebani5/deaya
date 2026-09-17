<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderDesignStatus;
use App\Support\Exceptions\DomainException;

/**
 * A version is judged once. Changing the verdict afterwards would erase the reason the next
 * version exists — and the whole point of keeping versions is that trail.
 */
final class DesignAlreadyReviewed extends DomainException
{
    public static function make(int $version, OrderDesignStatus $status): self
    {
        return new self("التصميم رقم {$version} تمت مراجعته مسبقاً وحالته «{$status->label()}»");
    }
}
