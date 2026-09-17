<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The last region of a city flagged `is_region_required` was about to be deleted.
 *
 * Allowing it would leave checkout asking the customer to pick a region from an empty list —
 * an order that can never be completed, and a bug that surfaces to a customer rather than to
 * whoever removed the region. The two states are only ever changed apart, so the fix is
 * explicit: clear the flag on the city first, then remove the region.
 */
final class CityRequiresAtLeastOneRegion extends DomainException
{
    public static function make(): self
    {
        return new self('لا يمكن حذف آخر منطقة في مدينة تتطلب اختيار منطقة — ألغِ اشتراط المنطقة أولاً');
    }

    /**
     * Reported against the region being deleted, so a form can show it inline rather than as a
     * bare toast.
     *
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['region' => [$this->getMessage()]];
    }
}
