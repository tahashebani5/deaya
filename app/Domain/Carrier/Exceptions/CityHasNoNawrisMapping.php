<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A delivery city nobody has mapped to a Nawris government yet.
 *
 * **Refused by name rather than sent as a null.** An empty `government` would come back as
 * whatever their validator says about it — unreadable to the clerk who pressed the button, and
 * indistinguishable from a real carrier problem. This says which city, so somebody can go and map
 * it. See NAWRIS-INTEGRATION.md §4.
 */
final class CityHasNoNawrisMapping extends DomainException
{
    public static function make(string $city): self
    {
        return new self("لم تُربط مدينة «{$city}» بمحافظة لدى نورس بعد");
    }
}
