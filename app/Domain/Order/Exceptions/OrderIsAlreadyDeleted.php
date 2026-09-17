<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A delete arriving at an order that is already in the archive.
 *
 * **A clean 4xx rather than the 500 the ledger would otherwise produce.** Without this the
 * second request would walk on into the credit-back, try to reverse a fulfilment movement that
 * already has a reversal, and break
 * `stock_movements_reverses_movement_id_unique` — a raw query error with nothing in it for the
 * person who merely tapped the button twice on a slow connection. The row is locked before this
 * is asked, so the two taps genuinely queue rather than both reading «حيّة».
 *
 * Names the code, because the app that sent this may well be showing a list: «الطلبية محذوفة»
 * on its own leaves the reader wondering which one.
 */
final class OrderIsAlreadyDeleted extends DomainException
{
    public static function make(string $code): self
    {
        return new self("الطلبية «{$code}» محذوفة أصلاً — وهي في الأرشيف");
    }
}
