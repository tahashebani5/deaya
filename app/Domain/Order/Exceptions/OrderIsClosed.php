<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * Nothing may be done to a delivered or cancelled order.
 *
 * Separate from {@see TransitionNotAllowed} because it answers a different question: not "that
 * move is wrong" but "this order is finished". A clerk who sees the first goes looking for the
 * right move; one who sees the second stops.
 */
final class OrderIsClosed extends DomainException
{
    public static function make(OrderStatus $status): self
    {
        return new self("الطلبية «{$status->label()}» ولا يمكن تعديلها");
    }
}
