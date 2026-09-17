<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * More came back than was ever missing.
 *
 * Refused rather than accepted and reported, for the reason `PaymentExceedsRemaining` gives: the
 * ordinary cause is a slipped keystroke while somebody is still standing at the counter, and the
 * moment to catch it is then.
 *
 * **A surplus is a real thing that happens — it is simply not this.** Buying thirty kilos to
 * cover a shortage of twenty is a purchase for the shelf, and its door is a purchase order, where
 * it becomes stock with a cost layer. Letting it in here would put ten kilos of somebody's
 * inventory inside an order's shortage that no order was ever short of.
 */
final class SupplyExceedsRemaining extends DomainException
{
    public static function make(string $quantity, string $remaining): self
    {
        return new self("الكمية ({$quantity}) أكبر من المتبقي من النقص ({$remaining})");
    }

    /**
     * The requirement being cut below what has already come back.
     *
     * The same rule read from the other end, and it needs its own sentence: «الكمية أكبر من
     * المتبقي» said about a *requirement* names the wrong number as the mistake and points at the
     * wrong field. Correcting a shortage of thirty down to ten after twenty have been bought is
     * refused because the twenty happened — the way down is to reverse the purchase.
     */
    public static function belowWhatIsSupplied(string $required, string $supplied): self
    {
        return new self(
            "الكمية المطلوبة ({$required}) أقل مما تم توفيره فعلاً ({$supplied}) — "
            .'اعكس عملية التوفير أولاً',
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['quantity' => [$this->getMessage()]];
    }
}
