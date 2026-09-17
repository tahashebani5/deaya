<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * More was asked to be repriced than the layer still holds.
 *
 * The quantity a revaluation names is checked **under the balance lock**, not at validation
 * time: an order going to print between the request being sent and the lock being granted is
 * exactly the thing that moves it. So this is a business refusal with a sentence naming both
 * numbers, rather than a validation rule that was true a moment ago.
 */
final class RevaluationExceedsRemaining extends DomainException
{
    public static function make(string $requested, string $remaining): self
    {
        return new self(
            "الكمية المطلوب تعديل تكلفتها ({$requested}) أكبر من المتبقي في الدفعة ({$remaining})"
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
