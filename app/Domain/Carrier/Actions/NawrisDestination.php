<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

/**
 * Where a parcel is going, in Nawris's vocabulary, resolved once at dispatch.
 *
 * A DTO rather than three loose arguments because these three travel together everywhere: they
 * are written onto the parcel at creation and replayed verbatim on every edit, and splitting them
 * up is how one of them eventually gets re-derived.
 */
final readonly class NawrisDestination
{
    public function __construct(
        public string $government,
        public ?string $area,
        public ?int $shippingCompanyId,
    ) {}
}
