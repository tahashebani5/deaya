<?php

declare(strict_types=1);

namespace App\Domain\Vendor\DTOs;

use App\Domain\Customer\DTOs\CustomerData;

final readonly class VendorData
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $contactPerson = null,
        public ?string $email = null,
        public ?string $address = null,
        /**
         * null means "not supplied". On create that becomes active; on update the current
         * value is kept, so omitting the field can never silently reactivate a vendor —
         * the same rule {@see CustomerData} follows.
         */
        public ?bool $isActive = null,
    ) {}

    /**
     * Built from already-validated request data — the one place an array crosses into the
     * domain.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim((string) $validated['name']),
            phone: trim((string) $validated['phone']),
            contactPerson: self::textOrNull($validated['contact_person'] ?? null),
            email: self::textOrNull($validated['email'] ?? null),
            address: self::textOrNull($validated['address'] ?? null),
            isActive: array_key_exists('is_active', $validated) && $validated['is_active'] !== null
                ? (bool) $validated['is_active']
                : null,
        );
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
