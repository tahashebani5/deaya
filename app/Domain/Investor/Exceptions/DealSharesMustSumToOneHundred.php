<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The percentages of a deal must account for all of it.
 *
 * Guarded in PHP because a row-level CHECK cannot see its siblings — and taken under the deal's
 * row lock, or two concurrent writes each pass on their own and the pair that survives sums to
 * something else entirely.
 */
final class DealSharesMustSumToOneHundred extends DomainException
{
    public static function make(string $sum): self
    {
        return new self("مجموع نسب المستثمرين يجب أن يساوي 100% بالضبط — المجموع الحالي {$sum}%");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['investors' => [$this->getMessage()]];
    }
}
