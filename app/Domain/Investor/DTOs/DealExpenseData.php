<?php

declare(strict_types=1);

namespace App\Domain\Investor\DTOs;

use App\Domain\Investor\Enums\DealExpenseKind;
use App\Domain\Investor\Support\Money;

/** A cost booked against a deal. `is_landed` is absent on purpose — the server decides it. */
final readonly class DealExpenseData
{
    public function __construct(
        public DealExpenseKind $kind,
        public string $name,
        public string $amount,
        public string $incurredOn,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            kind: DealExpenseKind::from((string) $validated['kind']),
            name: trim((string) $validated['name']),
            amount: Money::normalize($validated['amount']),
            incurredOn: (string) $validated['incurred_on'],
            notes: isset($validated['notes']) && trim((string) $validated['notes']) !== ''
                ? trim((string) $validated['notes'])
                : null,
        );
    }
}
