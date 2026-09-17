<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Exceptions;

use App\Domain\Shortage\Models\Shortage;
use App\Support\Exceptions\DomainException;

/**
 * What an order-born shortage is short *of* is the order's to say.
 *
 * The name, the unit and the required quantity are copied off the line and rewritten on every
 * sync, so an edit here would survive until the next time anybody touched that order and then
 * vanish. Refusing is kinder than accepting: an employee who watches a correction disappear a day
 * later has no way of finding out why. The requirement is changed on the order screen, which is
 * where the invoice changes with it. See {@see Shortage::isEditable()}.
 *
 * The chase is editable on both kinds — status, assignee and supplies all belong here.
 */
final class ShortageIsNotEditable extends DomainException
{
    public static function make(): self
    {
        return new self('نقصٌ مصدره طلبية — تُعدَّل كميته من شاشة الطلبية لا من هنا');
    }
}
