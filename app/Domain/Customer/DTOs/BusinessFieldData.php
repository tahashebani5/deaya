<?php

declare(strict_types=1);

namespace App\Domain\Customer\DTOs;

final readonly class BusinessFieldData
{
    public function __construct(
        public string $name,
        /** A field is offered the moment it is created; hiding one is a later, deliberate act. */
        public bool $isActive = true,
        /** Where it sits in the picker. Equal values fall back to the name. */
        public int $sortOrder = 0,
    ) {}

    /**
     * Built from already-validated request data — the one place an array is allowed to cross
     * into the domain.
     *
     * `store` and `update` both send the field's whole representation, so an omitted flag is
     * an instruction, not an absence: leaving `is_active` out means active.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            // Trimmed here rather than at the boundary: a name with a trailing space is a
            // second «شحن» as far as the unique index is concerned, and nobody would see why.
            name: trim((string) $validated['name']),
            isActive: (bool) ($validated['is_active'] ?? true),
            sortOrder: (int) ($validated['sort_order'] ?? 0),
        );
    }
}
