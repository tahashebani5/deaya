<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Enums\PaymentMethod;
use App\Support\Exceptions\DomainException;

/**
 * A bank transfer with no receipt attached.
 *
 * Cash is witnessed at the counter and a card leaves a trail at the bank. A transfer is the one
 * method whose only proof is a document the customer sends — and one recorded without it is a
 * number nobody can stand behind when the customer says they paid and the account says otherwise.
 *
 * The FormRequest says the same thing as a `required_if`, which is what gives the friendlier
 * field-level 422, and the table says it a third time as a CHECK. This copy is what makes the
 * rule hold for a console command or an importer.
 */
final class ReceiptRequiredForMethod extends DomainException
{
    public static function make(PaymentMethod $method): self
    {
        return new self("الدفع بـ«{$method->label()}» يتطلب إرفاق الواصل");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['receipt' => [$this->getMessage()]];
    }
}
