<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Actions\ApplyStockChange;
use App\Support\Exceptions\DomainException;

/**
 * A movement's product no longer agrees, in unit, with the balance it already has stock in.
 *
 * `warehouse_stocks.unit` is a snapshot taken once, the first time a (warehouse, variant) pair
 * gets a balance — see {@see ApplyStockChange::increase()}. If a
 * product's own `pricing_unit` is edited afterwards, a later movement would otherwise add
 * kilograms onto a balance that has only ever counted pieces (or the reverse) — a number nobody
 * could reconcile, the same class of problem {@see InsufficientStock} exists to prevent for a
 * balance going negative. Refused rather than silently mixed.
 */
final class UnitOfMeasurementMismatch extends DomainException
{
    public static function make(PricingUnit $balanceUnit, PricingUnit $productUnit): self
    {
        return new self(
            "وحدة الرصيد الحالية ({$balanceUnit->label()}) لا تطابق وحدة المنتج الحالية ({$productUnit->label()})"
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['quantity' => [$this->getMessage()]];
    }
}
