<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * The move is not on the map. The single refusal that makes the state machine real.
 */
final class TransitionNotAllowed extends DomainException
{
    public static function make(OrderStatus $from, OrderStatus $to): self
    {
        return new self("لا يمكن نقل الطلبية من «{$from->label()}» إلى «{$to->label()}»");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['status' => [$this->getMessage()]];
    }
}
