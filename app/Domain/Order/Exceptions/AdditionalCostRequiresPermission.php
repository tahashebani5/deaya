<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The field is hidden in the app for staff without the grant; this is the half that matters,
 * because a hidden field is a suggestion and a refused request is a rule.
 *
 * **Its own grant rather than the discount's.** The two look alike and are not: one gives money
 * away and the other asks the customer for more, and a business may reasonably trust a role with
 * exactly one of them. Sharing `orders.discount` would also have left the permissions screen
 * saying «منح خصم على الطلبية» beside a checkbox that does something else.
 */
final class AdditionalCostRequiresPermission extends DomainException
{
    public static function make(): self
    {
        return new self('لا تملك صلاحية إضافة تكلفة إضافية على الطلبية');
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
