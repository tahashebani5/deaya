<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * The lines close when printing starts: past that point the bags exist, and an order that
 * disagreed with the shop floor would be a wrong invoice rather than a correction.
 */
final class OrderItemsAreLocked extends DomainException
{
    public static function make(OrderStatus $status): self
    {
        return new self("لا يمكن تعديل بنود الطلبية بعد أن أصبحت «{$status->label()}»");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['items' => [$this->getMessage()]];
    }
}
