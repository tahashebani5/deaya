<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use App\Support\Exceptions\DomainException;

final class TransitionRequiresReason extends DomainException
{
    public static function make(OrderStatus $to): self
    {
        return new self("نقل الطلبية إلى «{$to->label()}» يتطلب ذكر السبب");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['reason' => [$this->getMessage()]];
    }
}
