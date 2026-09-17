<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Exceptions;

use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Support\Exceptions\DomainException;

/**
 * Editing a purchase order that has already moved past `new`. Once a vendor may have seen it —
 * or stock has started arriving against it — the paperwork on file has to stay what was actually
 * sent, the same reason a posted `StockArrival` is never edited.
 */
final class PurchaseOrderNotEditable extends DomainException
{
    public static function make(PurchaseOrderStatus $status): self
    {
        return new self("لا يمكن تعديل أمر الشراء وهو في حالة «{$status->label()}»");
    }
}
