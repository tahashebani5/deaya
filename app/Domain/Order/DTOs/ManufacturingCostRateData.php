<?php

declare(strict_types=1);

namespace App\Domain\Order\DTOs;

use App\Domain\Order\Enums\ManufacturingCostType;

final readonly class ManufacturingCostRateData
{
    public function __construct(
        public ?int $productId,
        public ?int $productVariantId,
        public ManufacturingCostType $costType,
        public string $ratePerUnit,
        public bool $isActive = true,
        public ?string $notes = null,
    ) {}

    /**
     * Built from already-validated request data — the one place an array is allowed to cross
     * into the domain. `product_id` and `product_variant_id` being mutually exclusive is the
     * request's own job to have refused already; see
     * `StoreManufacturingCostRateRequest::withValidator()`.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $notes = trim((string) ($validated['notes'] ?? ''));

        return new self(
            productId: self::intOrNull($validated['product_id'] ?? null),
            productVariantId: self::intOrNull($validated['product_variant_id'] ?? null),
            costType: ManufacturingCostType::from((string) $validated['cost_type']),
            ratePerUnit: number_format((float) $validated['rate_per_unit'], 3, '.', ''),
            isActive: (bool) ($validated['is_active'] ?? true),
            notes: $notes !== '' ? $notes : null,
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int) $value : null;
    }
}
