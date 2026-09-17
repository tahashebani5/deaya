<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Handing an order to the press while something is still missing from it.
 *
 * **«جاهزة للطباعة» is a promise to another department**: the goods are all here, they have been
 * weighed, and the press can start. An order that still carries a shortage on any line has not
 * made that true — the press would set up for a run it cannot finish, which is the exact cost the
 * handover status was added to remove.
 *
 * The way through is not this button. Leaving «نواقص» asks «كم وصل من كل بند؟» and subtracts what
 * arrived, so an order whose stock has actually turned up answers that form and passes here on the
 * same move — see `ChangeOrderStatus::recordShortages()`. The
 * message names what is still short rather than saying «غير مكتملة», because the person reading it
 * is standing in front of the shelves and needs to know which size to go and find.
 */
final class ShortageMustBeResolved extends DomainException
{
    /**
     * @param  list<string>  $stillShort  The labels of the sizes that have not arrived.
     */
    public static function make(array $stillShort): self
    {
        return new self(
            'لا يمكن تسليم الطلبية للمطبعة وبها نواقص — ما زال ناقصاً: '.implode('، ', $stillShort)
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['fields' => [$this->getMessage()]];
    }
}
