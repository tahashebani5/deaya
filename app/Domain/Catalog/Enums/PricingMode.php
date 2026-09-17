<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * Whether the price can be worked out from the catalogue, or has to be quoted by a person.
 *
 * The reinforced 3D paper bags are the second case: the catalogue says outright that prices are
 * not listed because sizes and specifications vary, and asks the customer to send the details.
 * Modelling that explicitly keeps the API honest — it refuses to invent a number rather than
 * returning a misleading zero.
 */
enum PricingMode: string
{
    case Tiered = 'tiered';
    case QuoteOnRequest = 'quote_on_request';

    public function label(): string
    {
        return match ($this) {
            self::Tiered => 'أسعار مدرجة',
            self::QuoteOnRequest => 'السعر حسب الطلب',
        };
    }

    public function hasListedPrices(): bool
    {
        return $this === self::Tiered;
    }
}
