<?php

declare(strict_types=1);

namespace App\Domain\Investor\DTOs;

use App\Domain\Investor\Models\InvestorDeal;

/**
 * «مَن موّل أمر الشراء هذا، وبكم» — the whole funding screen in one payload.
 *
 * No percentages: they come out of the money. And no shelves either, unless this deal is taking
 * only some of the order's lines — `stock_item_ids` chooses them, and omitted it means every
 * line nobody has claimed yet. What is left for a person to type is a name and a column of
 * amounts.
 */
final readonly class FundPurchaseOrderData
{
    /**
     * @param  list<DealFunderData>  $funders
     * @param  list<int>|null  $stockItemIds  the order's lines this deal funds; null means every
     *                                        line nobody has claimed yet
     */
    public function __construct(
        public array $funders,
        public ?array $stockItemIds = null,
        public ?string $investorProfitSharePercent = null,
        /**
         * سعر السادة this deal sells its plain stock to the press at, by weight. Null keeps the
         * deal on the road every deal walked before it existed — see
         * {@see InvestorDeal}.
         */
        public ?string $printingSalePrice = null,
        public ?string $notes = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            funders: array_map(
                fn (array $row) => DealFunderData::fromArray($row),
                $validated['investors'] ?? [],
            ),
            stockItemIds: isset($validated['stock_item_ids'])
                ? array_map('intval', $validated['stock_item_ids'])
                : null,
            investorProfitSharePercent: isset($validated['investor_profit_share_percent'])
                ? number_format((float) $validated['investor_profit_share_percent'], 2, '.', '')
                : null,
            // Three places, matching `stock_batches.unit_cost` — the number it is subtracted
            // from every time it is used.
            printingSalePrice: isset($validated['printing_sale_price'])
                ? number_format((float) $validated['printing_sale_price'], 3, '.', '')
                : null,
            notes: isset($validated['notes']) && trim((string) $validated['notes']) !== ''
                ? trim((string) $validated['notes'])
                : null,
        );
    }
}
