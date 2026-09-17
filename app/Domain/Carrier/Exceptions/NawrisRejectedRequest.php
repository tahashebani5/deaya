<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Nawris answered, and said no.
 *
 * **Their failures arrive as HTTP 200.** A logical error is `{"success": 0, "error_msg": "…"}`
 * with a perfectly successful status line, so checking the HTTP code alone would treat every one
 * of these as a success and write a parcel row for a shipment that does not exist. The client
 * reads the body and raises this instead.
 *
 * Their message is carried through verbatim: it is the only thing that tells support what was
 * actually wrong, and «فشل الطلب» tells them nothing.
 */
final class NawrisRejectedRequest extends DomainException
{
    public static function make(string $operation, ?string $reason = null): self
    {
        $detail = $reason !== null && trim($reason) !== '' ? ": {$reason}" : '';

        return new self("رفضت شركة نورس {$operation}{$detail}");
    }
}
