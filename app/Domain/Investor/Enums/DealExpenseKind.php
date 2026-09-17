<?php

declare(strict_types=1);

namespace App\Domain\Investor\Enums;

/**
 * What a deal expense is for.
 *
 * A closed list beside a free-text `name`, so «كم دفعنا جماركاً هذا العام؟» is a query while the
 * invoice number stays readable. There is deliberately **no «شراء»**: the cost of the goods is
 * the cost of the layers they arrived as, and typing it again here would create a second number
 * that can disagree with the shelf.
 */
enum DealExpenseKind: string
{
    case Shipping = 'shipping';
    case Customs = 'customs';
    case Transport = 'transport';
    case Storage = 'storage';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Shipping => 'شحن',
            self::Customs => 'جمارك',
            self::Transport => 'نقل',
            self::Storage => 'تخزين',
            self::Other => 'أخرى',
        };
    }
}
