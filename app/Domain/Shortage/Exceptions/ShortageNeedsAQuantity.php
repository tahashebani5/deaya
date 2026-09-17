<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A shortage of nothing is not a shortage.
 *
 * Guarded in the domain as well as in the request, and a third time as a CHECK, so a console
 * command cannot write a row the API refuses — RULES §8.
 */
final class ShortageNeedsAQuantity extends DomainException
{
    public static function make(): self
    {
        return new self('الكمية الناقصة يجب أن تكون أكبر من صفر');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['required_quantity' => [$this->getMessage()]];
    }
}
