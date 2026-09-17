<?php

declare(strict_types=1);

namespace App\Domain\Investor\DTOs;

use App\Domain\Investor\Support\Money;

/**
 * One investor's stake as the form sends it.
 *
 * `sharePercent` is his slice **of the investors' half**, not of the deal's whole profit — so
 * «للمستثمرين 50٪، ولأحمد 60٪ منها» is two numbers doing two jobs rather than one doing both.
 */
final readonly class DealShareData
{
    public function __construct(
        public int $investorId,
        public string $sharePercent,
        public string $committedAmount = '0.00',
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            investorId: (int) $row['investor_id'],
            sharePercent: number_format((float) $row['share_percent'], 4, '.', ''),
            committedAmount: Money::normalize($row['committed_amount'] ?? 0),
            notes: isset($row['notes']) && trim((string) $row['notes']) !== ''
                ? trim((string) $row['notes'])
                : null,
        );
    }
}
