<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\PaymentStatus;
use App\Support\Exceptions\DomainException;

/**
 * Somebody typed 500 where they meant 50.
 *
 * Refused rather than accepted-and-reported, because the ordinary cause is a slipped keystroke
 * at a counter and the moment to catch it is while the customer is still standing there. A
 * deposit larger than the whole order is not a thing that happens.
 *
 * **The rule binds at the moment of recording, not forever.** An order whose total is later cut
 * by a discount can end up paid more than it costs without anybody having done anything wrong —
 * that is {@see PaymentStatus::Overpaid}, which is reported so the difference can be refunded.
 * Enforcing "paid may never exceed total" as a standing invariant would make granting that
 * discount impossible.
 */
final class PaymentExceedsRemaining extends DomainException
{
    public static function make(string $amount, string $remaining): self
    {
        return new self("المبلغ ({$amount}) أكبر من المتبقي على الطلبية ({$remaining})");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['amount' => [$this->getMessage()]];
    }
}
