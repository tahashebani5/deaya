<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTOs;

use App\Domain\Delivery\Enums\FulfilmentType;

final readonly class CityData
{
    public function __construct(
        public string $name,
        /**
         * null means "not supplied": a new city is somewhere we deliver to, and an update leaves
         * whatever is there alone.
         *
         * The one field on this DTO that is *not* replaced wholesale, and deliberately so. A
         * pin the user cleared should clear; a branch quietly becoming a delivery city because
         * a form posted back without the field would move orders to the wrong status, and
         * nothing on screen would say why.
         */
        public ?FulfilmentType $fulfilmentType = null,
        public bool $isRegionRequired = false,
        /** null means "no rate agreed yet" — never "free". Free is the string '0.00'. */
        public ?string $deliveryPrice = null,
        public ?string $darbBranch = null,
        /** Nawris's own id for this destination. Null means the city is not one of theirs. */
        public ?string $nawrisGovernmentId = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {}

    /**
     * Built from already-validated request data — the one place an array is allowed to cross
     * into the domain.
     *
     * `store` and `update` both send the city's whole representation, so a field left out is an
     * instruction to clear it rather than to keep whatever is there.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $price = $validated['delivery_price'] ?? null;

        return new self(
            name: trim((string) $validated['name']),
            fulfilmentType: isset($validated['fulfilment_type'])
                ? FulfilmentType::from((string) $validated['fulfilment_type'])
                : null,
            isRegionRequired: (bool) ($validated['is_region_required'] ?? false),
            // Cast through string, never float: the column is decimal and money that is summed
            // must not pick up binary drift on the way in.
            deliveryPrice: $price !== null && $price !== '' ? number_format((float) $price, 2, '.', '') : null,
            darbBranch: self::textOrNull($validated['darb_branch'] ?? null),
            nawrisGovernmentId: self::textOrNull($validated['nawris_government_id'] ?? null),
            latitude: isset($validated['latitude']) ? (float) $validated['latitude'] : null,
            longitude: isset($validated['longitude']) ? (float) $validated['longitude'] : null,
        );
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
