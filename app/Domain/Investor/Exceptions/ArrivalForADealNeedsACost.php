<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Deal stock priced at nothing would give the deal a 100% margin on goods that cost money.
 *
 * A missing unit cost becomes the documented `'0.000'` placeholder, which means «nobody recorded
 * a price» — harmless on the company's own stock, and a fabricated profit on somebody else's.
 */
final class ArrivalForADealNeedsACost extends DomainException
{
    public static function make(): self
    {
        return new self('بضاعة الصفقة لا تُستلم بلا تكلفة وحدة');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['items' => [$this->getMessage()]];
    }
}
