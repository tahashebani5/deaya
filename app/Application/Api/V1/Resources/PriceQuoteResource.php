<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Catalog\DTOs\PriceQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PriceQuote
 */
class PriceQuoteResource extends JsonResource
{
    public function __construct(private readonly PriceQuote $quote)
    {
        parent::__construct($quote);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'quantity' => $this->quote->quantity,
            'unit' => $this->quote->unit->value,
            'unit_label' => $this->quote->unit->label(),

            // Decimal strings throughout — see PriceQuote for why money never becomes a float.
            'unit_price' => $this->quote->unitPrice,
            'total' => $this->quote->total,

            // Which quantity break produced this price.
            'applied_tier_min_quantity' => $this->quote->appliedTierMinQuantity,

            // The saving still on the table, so the app can say "order 47 more and the unit
            // price drops". Null when the customer is already on the best rate.
            'next_tier' => $this->quote->nextTierMinQuantity === null ? null : [
                'min_quantity' => $this->quote->nextTierMinQuantity,
                'unit_price' => $this->quote->nextTierUnitPrice,
                'quantity_to_reach' => $this->quote->quantityToNextTier,
            ],
        ];
    }
}
