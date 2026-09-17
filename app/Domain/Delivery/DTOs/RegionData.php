<?php

declare(strict_types=1);

namespace App\Domain\Delivery\DTOs;

final readonly class RegionData
{
    public function __construct(
        public string $name,
        /** شركة درب's own zone code, e.g. `s18`. Null until they publish one. */
        public ?string $code = null,
        public ?string $darbBranch = null,
        /** Nawris's own id for this area, sent beside the city's government. */
        public ?string $nawrisAreaId = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
    ) {}

    /**
     * No `city_id` here, deliberately: a region's city comes from the URL it was posted to, so a
     * request body can never move one city's neighbourhood into another's list.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim((string) $validated['name']),
            code: self::textOrNull($validated['code'] ?? null),
            darbBranch: self::textOrNull($validated['darb_branch'] ?? null),
            nawrisAreaId: self::textOrNull($validated['nawris_area_id'] ?? null),
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
