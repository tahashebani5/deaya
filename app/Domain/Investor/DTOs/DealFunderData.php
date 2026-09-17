<?php

declare(strict_types=1);

namespace App\Domain\Investor\DTOs;

use App\Domain\Investor\Support\Money;

/**
 * One man and what he is putting into this shipment.
 *
 * **No percentage.** It is derived from these amounts ({@see Money::allocatePercent}), because a
 * typed percentage beside a typed amount is two numbers that can disagree, and the money is the
 * one somebody can point at a receipt for.
 */
final readonly class DealFunderData
{
    public function __construct(
        public int $investorId,
        public string $amount,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            investorId: (int) $row['investor_id'],
            amount: Money::normalize($row['amount'] ?? 0),
            notes: isset($row['notes']) && trim((string) $row['notes']) !== ''
                ? trim((string) $row['notes'])
                : null,
        );
    }
}
