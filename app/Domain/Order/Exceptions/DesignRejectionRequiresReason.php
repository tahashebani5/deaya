<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

final class DesignRejectionRequiresReason extends DomainException
{
    public static function make(): self
    {
        return new self('رفض التصميم يتطلب ذكر السبب');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['rejection_reason' => [$this->getMessage()]];
    }
}
