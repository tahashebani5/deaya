<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Goods that are real stock have to land somewhere.
 *
 * **The refusal that makes the whole arrangement hold.** A shortage naming a size names a shelf,
 * and the order that was short will draw the full quantity off that shelf when it reaches
 * «جاهزة» — so a purchase recorded without saying where it went leaves the order to fail later
 * with `OrderStockShortfall`, a message about a warehouse balance that says nothing about the
 * sacks somebody bought last week.
 *
 * Asked for at the counter, where the person knows the answer, rather than inferred from the
 * order: the order has no warehouse of its own until the moment it is fulfilled, and guessing one
 * would put stock on a shelf nobody chose.
 */
final class SupplyNeedsAWarehouse extends DomainException
{
    public static function make(): self
    {
        return new self('المخزن مطلوب — البضاعة المشتراة تدخل المخزون لتُخصم منه الطلبية');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['warehouse_id' => [$this->getMessage()]];
    }
}
