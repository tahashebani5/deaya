<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Actions\MarkReadyMessageSent;
use App\Domain\Order\Models\Order;
use App\Support\Exceptions\DomainException;

/**
 * Somebody confirmed «رسالة الجاهزية» on an order whose bags do not exist yet.
 *
 * The message says the order is ready to collect, so there is nothing to send before the order
 * has been ready at least once — see {@see Order::readyMessageApplies()} for why that is read
 * from `ready_at` rather than from the status, and {@see MarkReadyMessageSent} for the write
 * this refuses.
 *
 * The app is told the same thing by `ready_message_applies`, so this is the guard behind a box
 * that was never drawn rather than a refusal anybody should meet.
 */
final class ReadyMessageNeedsAReadyOrder extends DomainException
{
    public static function make(): self
    {
        return new self('لا تُؤكَّد رسالة الجاهزية قبل أن تبلغ الطلبية حالة «جاهزة»');
    }
}
