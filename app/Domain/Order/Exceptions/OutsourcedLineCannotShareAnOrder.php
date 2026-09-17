<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A وسيط line in an order that also holds something دعاية makes or stocks.
 *
 * **Until this existed, a mixed order was taken and quietly put on the wrong road.**
 * `ResolveOrderFlow` asks its lines to be unanimous and falls back to `OrderFlow::Standard` when
 * they are not — which is the right answer for «سادة» beside «مطبوعة», and the wrong one the
 * moment a وسيط line is in the order. On the standard road that order walks «قيد التصميم» and
 * «قيد الطباعة» for goods no press of ours touches, and `OrderFlow::deductsStock()` answers true
 * for it, so entering «جاهزة» asks a warehouse for goods that were never on a shelf of ours. The
 * order is not refused anywhere, so nobody finds out until the shelf is short.
 *
 * **Which is why the rule is unanimity about one mode rather than a limit on lines.** Several
 * وسيط lines in one order are fine — the road is unanimous, and the vendor is named once at
 * acceptance. What cannot happen is a وسيط line beside one that is not.
 *
 * **Thrown from the domain rather than checked in the request**, for the reason
 * {@see OutsourcedOrderNeedsAVendor} gives: the question is about production modes read off the
 * lines' categories, so it cannot be answered until the lines exist. `CreateOrder` asks it inside
 * the same transaction, immediately after `ResolveOrderFlow`, so a refused order is never left
 * half-taken — and both doors, the clerk's and the customer app's, go through `CreateOrder`.
 */
final class OutsourcedLineCannotShareAnOrder extends DomainException
{
    public static function make(): self
    {
        return new self(
            'المنتجات التي ينفّذها مورد خارجي تُطلب في طلبية مستقلة — افصلها عن باقي المنتجات',
        );
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return ['items' => [$this->getMessage()]];
    }
}
