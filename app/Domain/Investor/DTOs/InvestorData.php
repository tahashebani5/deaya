<?php

declare(strict_types=1);

namespace App\Domain\Investor\DTOs;

/** What a request may say about an investor. Never his code, never his login. */
final readonly class InvestorData
{
    public function __construct(
        public string $name,
        public ?string $phone = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            name: trim((string) $validated['name']),
            phone: self::textOrNull($validated['phone'] ?? null),
            notes: self::textOrNull($validated['notes'] ?? null),
        );
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = is_string($value) ? trim($value) : null;

        return $text === '' ? null : $text;
    }
}
