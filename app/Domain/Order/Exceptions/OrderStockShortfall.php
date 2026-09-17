<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Order\Actions\DeductOrderStock;
use App\Domain\Order\DTOs\StockShortfall;
use App\Support\Exceptions\DomainException;

/**
 * An order asks its warehouse for more than the warehouse holds — of one size, or of several.
 *
 * **The whole order is weighed before anything leaves.** {@see InsufficientStock} is thrown by
 * the balance itself, one movement at a time, and it knows only two numbers: a foreman moving an
 * order with four sizes on it was told a quantity was short without being told which size, and
 * the three lines after the failing one were never even reached — so a second size short stayed
 * hidden until the first was restocked. {@see DeductOrderStock} checks every line up front and
 * throws this instead, naming all of them at once.
 *
 * The names are the lines' own snapshots, so an order refers to the sizes by what it was written
 * with rather than what the catalogue has since been renamed to.
 *
 * Reported against the warehouse field, because that is the input the person reading this can
 * actually change on the screen that produced it — the other honest answer, restocking, is not
 * something a form can offer.
 */
final class OrderStockShortfall extends DomainException
{
    /**
     * @param  non-empty-list<StockShortfall>  $shortfalls
     */
    private function __construct(private readonly array $shortfalls)
    {
        parent::__construct(
            count($shortfalls) === 1
                ? self::sentence($shortfalls[0])
                : 'لا يوجد رصيد كافٍ في المخزن للمواد التالية'
        );
    }

    /**
     * @param  non-empty-list<StockShortfall>  $shortfalls
     */
    public static function make(array $shortfalls): self
    {
        return new self($shortfalls);
    }

    /**
     * One line per short size when there is more than one — the app renders them under the
     * message rather than as a second toast. A single shortfall repeats the message here and
     * the app drops the duplicate, which keeps the shape the same either way.
     *
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return [
            'fields.warehouse_id' => count($this->shortfalls) === 1
                ? [self::sentence($this->shortfalls[0])]
                : array_map(self::line(...), $this->shortfalls),
        ];
    }

    /** The one-size wording, which reads as a sentence because it is the whole message. */
    private static function sentence(StockShortfall $shortfall): string
    {
        return "الكمية المتوفرة من «{$shortfall->name}» في المخزن ({$shortfall->available})"
            ." لا تكفي للكمية المطلوبة ({$shortfall->required})";
    }

    /** The list wording, which reads as an entry because the message above it says the rest. */
    private static function line(StockShortfall $shortfall): string
    {
        return "«{$shortfall->name}»: المتوفر ({$shortfall->available})"
            ." والمطلوب ({$shortfall->required})";
    }
}
