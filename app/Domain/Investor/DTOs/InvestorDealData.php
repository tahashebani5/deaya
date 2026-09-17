<?php

declare(strict_types=1);

namespace App\Domain\Investor\DTOs;

/**
 * A deal as the form sends it.
 *
 * `investorProfitSharePercent` is nullable on the way in: omitted, the company default seeds it.
 * That is the whole of A1 — one number the business edits once, copied onto each deal at birth
 * and never read again for that deal, so moving the default tomorrow cannot disturb a closed
 * deal's figures.
 */
final readonly class InvestorDealData
{
    /**
     * @param  list<DealItemData>  $items
     * @param  list<DealShareData>  $shares
     */
    public function __construct(
        public string $openedOn,
        public array $items,
        public array $shares,
        public ?int $productId = null,
        public ?string $investorProfitSharePercent = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            openedOn: (string) $validated['opened_on'],
            items: array_map(
                fn (array $row) => DealItemData::fromArray($row),
                $validated['items'] ?? [],
            ),
            shares: array_map(
                fn (array $row) => DealShareData::fromArray($row),
                $validated['investors'] ?? [],
            ),
            productId: isset($validated['product_id']) ? (int) $validated['product_id'] : null,
            investorProfitSharePercent: isset($validated['investor_profit_share_percent'])
                ? number_format((float) $validated['investor_profit_share_percent'], 2, '.', '')
                : null,
            notes: isset($validated['notes']) && trim((string) $validated['notes']) !== ''
                ? trim((string) $validated['notes'])
                : null,
        );
    }
}
