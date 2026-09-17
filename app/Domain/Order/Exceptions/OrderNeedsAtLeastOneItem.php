<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

final class OrderNeedsAtLeastOneItem extends DomainException
{
    public static function make(): self
    {
        return new self('الطلبية يجب أن تحتوي على منتج واحد على الأقل');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['items' => [$this->getMessage()]];
    }
}
