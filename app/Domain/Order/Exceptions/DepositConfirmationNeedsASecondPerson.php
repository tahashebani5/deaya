<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The person who said the عربون was paid may not also be the person who confirms it arrived.
 *
 * **The one accounting control in this feature, and the reason the claim is cheap to make.**
 * Moving an order to «عربون مدفوع» asks for no proof — the counter is told the customer paid and
 * the job has to start — so the statement that the money is genuinely in the account has to come
 * from somewhere else, or it is the same person vouching for themselves.
 *
 * **Enforced on the record, not on the roles.** A rule kept as «لا تمنح الصلاحيتين لشخص واحد»
 * holds only until somebody is given an admin role, which is the day it stops holding silently.
 * `orders.deposit_claimed_by` is compared against whoever is ticking, so the refusal survives any
 * configuration — and `Order::depositIsConfirmableBy()` publishes the same answer in the payload,
 * so the app greys the box rather than letting a person tap and be told no.
 *
 * **It guards ticking only.** Clearing a confirmation is open to anyone holding the grant:
 * un-ticking makes the record stricter rather than looser, and a mistake nobody may undo is worse
 * than a mistake anyone may.
 *
 * The consequence the business accepted: **at least two people must hold
 * `orders.deposit.confirm`**, or no deposit can ever be confirmed on an order they moved
 * themselves.
 */
final class DepositConfirmationNeedsASecondPerson extends DomainException
{
    public static function make(): self
    {
        return new self('لا يمكن لمن نقل الطلبية إلى «عربون مدفوع» أن يؤكّد استلام العربون — يلزم شخص آخر');
    }
}
