<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Domain\Shortage\Enums\ShortageStatus;
use App\Support\Exceptions\DomainException;

/**
 * A move the machine does not have.
 *
 * **Including every move to «مكتمل».** That status is in no map at all — it is written by the
 * arithmetic when the last of the quantity is supplied, never chosen — so a request naming it
 * lands here, and the message says what is actually wrong rather than listing it as a
 * destination worth trying again later. See {@see ShortageStatus}.
 */
final class ShortageTransitionNotAllowed extends DomainException
{
    public static function make(ShortageStatus $from, ShortageStatus $to): self
    {
        if ($to === ShortageStatus::Completed) {
            return new self(
                'لا يُحوَّل النقص إلى «مكتمل» يدوياً — يكتمل وحده عند توفير كامل الكمية',
            );
        }

        return new self("لا يمكن تحويل النقص من «{$from->label()}» إلى «{$to->label()}»");
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['status' => [$this->getMessage()]];
    }
}
