<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Editing a purchase order that investors have already funded.
 *
 * A funded order stays `new` until the lorry arrives, which is the same state that keeps it
 * editable — so without this, somebody could retype a line's cost under a deal whose ownership
 * percent was worked out from that cost, shown to the partners and frozen. The lines and the
 * money were agreed together; changing either alone is a new deal, not an edit.
 */
final class PurchaseOrderIsFunded extends DomainException
{
    /**
     * @param  list<string>  $codes  the deals standing on the order
     */
    public static function make(array $codes): self
    {
        $deals = implode('، ', $codes);

        return new self("أمر الشراء مموَّل بالصفقة {$deals}؛ بنوده وتكلفته مقفلة بعد التمويل");
    }
}
