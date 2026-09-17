<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A product the catalogue prices «حسب الطلب» has no number to fall back on, so the clerk has to
 * name one. Refusing the order outright would make a whole category of the catalogue — the
 * reinforced 3D paper bags — impossible to sell.
 */
final class ManualPriceRequired extends DomainException
{
    public static function make(string $productName): self
    {
        return new self("المنتج «{$productName}» سعره حسب الطلب، ويجب إدخال سعر الوحدة يدوياً");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['items' => [$this->getMessage()]];
    }
}
