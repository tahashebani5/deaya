<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * What a quantity means for this product, and therefore what its price is *per*.
 *
 * **{@see label()} is the one place the Arabic word for a unit is written**, and every screen,
 * error and field label in the system prints what it returns — so «كجم» rather than «كيلوغرام»
 * is decided here, once. The abbreviation is what the owner asked for: the word sits beside a
 * number on a phone, where four syllables of column width buy nothing a reader did not already
 * know. «قطعة» stays whole because it has no shorter form.
 */
enum PricingUnit: string
{
    case Piece = 'piece';
    case Kilogram = 'kilogram';

    public function label(): string
    {
        return match ($this) {
            self::Piece => 'قطعة',
            self::Kilogram => 'كجم',
        };
    }

    /**
     * Pieces are countable, so half a piece is meaningless; weight is not.
     * This is what stops an order for 2.5 shipping bags.
     */
    public function requiresWholeQuantities(): bool
    {
        return $this === self::Piece;
    }
}
