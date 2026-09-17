<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * Once the parcel is with somebody outside the business, the address on our screen and the
 * address on the label have already parted company — and only the label is real.
 */
final class DestinationCannotChange extends DomainException
{
    public static function make(OrderStatus $status): self
    {
        return new self("لا يمكن تغيير وجهة الطلبية وحالتها «{$status->label()}»");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['city_id' => [$this->getMessage()]];
    }
}
