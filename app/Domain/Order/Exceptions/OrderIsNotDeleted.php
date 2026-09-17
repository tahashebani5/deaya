<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * A restore asked of an order that is not in the archive.
 *
 * The twin of {@see OrderIsNotCancelled}, and refused for the same reason: the caller asked for
 * an *undo* of something that has not happened. Naming the status the order is actually standing
 * in is what turns «لا يمكن» into an answer — an order the archive screen still shows because a
 * colleague restored it a minute ago reads as an explanation rather than a fault.
 */
final class OrderIsNotDeleted extends DomainException
{
    public static function make(OrderStatus $status): self
    {
        return new self("الطلبية ليست محذوفة — حالتها «{$status->label()}»، ولا يوجد حذف يُستعاد منه");
    }
}
