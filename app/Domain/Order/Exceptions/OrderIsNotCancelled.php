<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * Undoing a cancellation on an order that was never cancelled.
 *
 * Separate from {@see TransitionNotAllowed} because it is not a move being refused: the caller
 * asked for a *correction* of something that has not happened, and the right answer names the
 * status the order is actually standing in.
 */
final class OrderIsNotCancelled extends DomainException
{
    public static function make(OrderStatus $status): self
    {
        return new self("الطلبية ليست ملغاة — حالتها «{$status->label()}»، ولا يوجد إلغاء يُتراجع عنه");
    }
}
