<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Enums\WarehouseType;

final readonly class WarehouseData
{
    public function __construct(
        public string $name,
        public WarehouseType $type,
        /** null means nobody has written the address down yet — not that it has none. */
        public ?string $location = null,
    ) {}

    /**
     * Built from already-validated request data — the one place an array is allowed to cross
     * into the domain.
     *
     * `store` and `update` both send the warehouse's whole representation, so a field left out
     * is an instruction to clear it rather than to keep whatever is there.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim((string) $validated['name']),
            // `from`, not `tryFrom`: the FormRequest has already refused anything else, so a
            // value that is not a case here is a bug worth a 500 rather than a silent null.
            type: WarehouseType::from((string) $validated['type']),
            location: self::textOrNull($validated['location'] ?? null),
        );
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
