<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Not every row is undoable by hand.
 *
 * An earning is undone by the order that produced it changing, never by somebody reversing it —
 * otherwise the ledger and the order can say two different things about one sale. And a reversal
 * is not itself reversible: «عكسُ عكسٍ متاهةٌ بلا أرضية».
 */
final class EntryCannotBeReversed extends DomainException
{
    public static function make(string $reason): self
    {
        return new self($reason);
    }
}
