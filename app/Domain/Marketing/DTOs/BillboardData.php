<?php

declare(strict_types=1);

namespace App\Domain\Marketing\DTOs;

use Carbon\CarbonImmutable;

/**
 * What a request says about a banner.
 *
 * **Every field is nullable and null means «not supplied», not «clear it».** That is what lets
 * `UpdateBillboard` treat a `PATCH` as a partial edit: switching a banner off must not wipe the
 * schedule somebody set last week, and that is the edit staff make most.
 */
final readonly class BillboardData
{
    public function __construct(
        public ?string $title = null,
        public ?int $productId = null,
        public ?string $externalUrl = null,
        public ?int $sortOrder = null,
        public ?bool $isActive = null,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
    ) {}

    /**
     * Built from already-validated request data — the one place an array crosses into this
     * context.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            title: self::stringOrNull($validated, 'title'),
            productId: isset($validated['product_id']) ? (int) $validated['product_id'] : null,
            externalUrl: self::stringOrNull($validated, 'external_url'),
            sortOrder: isset($validated['sort_order']) ? (int) $validated['sort_order'] : null,
            isActive: array_key_exists('is_active', $validated) && $validated['is_active'] !== null
                ? filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN)
                : null,
            startsAt: self::dateOrNull($validated, 'starts_at'),
            endsAt: self::dateOrNull($validated, 'ends_at'),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private static function stringOrNull(array $validated, string $key): ?string
    {
        $value = isset($validated[$key]) ? trim((string) $validated[$key]) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private static function dateOrNull(array $validated, string $key): ?CarbonImmutable
    {
        return isset($validated[$key]) && $validated[$key] !== ''
            ? CarbonImmutable::parse((string) $validated[$key])
            : null;
    }
}
