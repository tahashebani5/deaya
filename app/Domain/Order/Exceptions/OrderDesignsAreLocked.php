<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\OrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * The artwork is settled once the press is running against it.
 *
 * Deliberately a different line from the one the lines close on: a quantity is a number the
 * shop floor can still act on, while a design has already been printed. Changing it means
 * sending the order back to «قيد التصميم» — a move somebody makes on purpose, and one the
 * timeline records.
 */
final class OrderDesignsAreLocked extends DomainException
{
    public static function make(OrderStatus $status): self
    {
        return new self(
            'إضافة التصاميم متاحة في «جديدة» و«قيد التصميم» — '
            ."والطلبية الآن «{$status->label()}»، وتعديل التصميم يبدأ بإرجاعها إلى «قيد التصميم»",
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['customer_design_id' => [$this->getMessage()]];
    }
}
