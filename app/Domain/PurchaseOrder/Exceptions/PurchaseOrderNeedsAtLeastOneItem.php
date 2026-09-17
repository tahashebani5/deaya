<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Sending a purchase order with no lines on it — nothing for the vendor to fulfil and nothing
 * for a shipment to ever be matched against.
 */
final class PurchaseOrderNeedsAtLeastOneItem extends DomainException
{
    public static function make(): self
    {
        return new self('يجب أن يحتوي أمر الشراء على بند واحد على الأقل قبل إرساله');
    }
}
