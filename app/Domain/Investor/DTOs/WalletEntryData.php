<?php

declare(strict_types=1);

namespace App\Domain\Investor\DTOs;

use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Support\Money;
use Carbon\CarbonImmutable;

/**
 * One movement of money a person is recording by hand.
 *
 * Only the four types a form may send ever reach here — an earning is written by the order flow
 * and a release by closing a deal, and offering either on a form would be offering somebody the
 * chance to invent one.
 */
final readonly class WalletEntryData
{
    public function __construct(
        public int $investorId,
        public WalletEntryType $type,
        public string $amount,
        public ?int $investorDealId = null,
        public ?string $method = null,
        public ?string $reference = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated, int $investorId): self
    {
        return new self(
            investorId: $investorId,
            type: WalletEntryType::from((string) $validated['type']),
            amount: Money::normalize($validated['amount']),
            investorDealId: isset($validated['investor_deal_id']) ? (int) $validated['investor_deal_id'] : null,
            method: isset($validated['method']) ? (string) $validated['method'] : null,
            reference: isset($validated['reference']) && trim((string) $validated['reference']) !== ''
                ? trim((string) $validated['reference'])
                : null,
            occurredAt: isset($validated['occurred_at'])
                ? CarbonImmutable::parse((string) $validated['occurred_at'])
                : null,
            notes: isset($validated['notes']) && trim((string) $validated['notes']) !== ''
                ? trim((string) $validated['notes'])
                : null,
        );
    }
}
