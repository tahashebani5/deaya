<?php

declare(strict_types=1);

namespace App\Domain\Investor\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A signed-in user who is not an investor asked for the investor portal.
 *
 * 404 rather than 403: the record does not exist for them, and «ممنوع» would imply there is
 * something behind the door to be let into.
 */
final class InvestorHasNoAccount extends DomainException
{
    public static function make(): self
    {
        return new self('لا يوجد حساب مستثمر مرتبط بهذا المستخدم');
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
