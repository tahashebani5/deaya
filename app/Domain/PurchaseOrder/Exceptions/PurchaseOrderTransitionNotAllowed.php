<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Exceptions;

use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * A status move {@see PurchaseOrderStatus::allowedNext()} does not list — a cancelled order
 * being sent, a completed one being cancelled, and so on.
 */
final class PurchaseOrderTransitionNotAllowed extends DomainException
{
    public static function make(PurchaseOrderStatus $from, PurchaseOrderStatus $to): self
    {
        return new self("لا يمكن نقل أمر الشراء من «{$from->label()}» إلى «{$to->label()}»");
    }
}
