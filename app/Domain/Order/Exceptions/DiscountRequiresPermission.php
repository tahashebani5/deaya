<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The field is hidden in the app for staff without the grant; this is the half that matters,
 * because a hidden field is a suggestion and a refused request is a rule.
 */
final class DiscountRequiresPermission extends DomainException
{
    public static function make(): self
    {
        return new self('لا تملك صلاحية منح خصم على الطلبية');
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
