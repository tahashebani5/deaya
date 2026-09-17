<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A movement would have taken more out of a warehouse than is in it.
 *
 * Refused rather than clamped, and refused rather than allowed to go negative. A negative shelf
 * is not a smaller problem than a rejected request — it is a balance that nobody can reconcile,
 * discovered weeks later by whoever is counting. The honest answers are "record what actually
 * arrived" or "adjust the count first", and both are things a person has to decide.
 *
 * Carries the two numbers, because "not enough stock" without them sends the storekeeper back to
 * another screen to find out how much there is.
 */
final class InsufficientStock extends DomainException
{
    public static function make(string $available, string $requested): self
    {
        return new self(
            "الكمية المتوفرة في المخزن ({$available}) لا تكفي للكمية المطلوبة ({$requested})"
        );
    }

    /**
     * Reported against the quantity field, so the form shows it on the input that caused it.
     *
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['quantity' => [$this->getMessage()]];
    }
}
