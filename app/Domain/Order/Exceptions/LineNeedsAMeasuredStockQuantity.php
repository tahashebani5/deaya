<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\Actions\DeductOrderStock;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\TransitionFields;
use App\Support\Exceptions\DomainException;

/**
 * An order is being shelved with a line whose deduction nobody could work out.
 *
 * **«٥٠٠ قطعة» sold off a shelf counted in kilograms has no automatic answer.** Bags of one size
 * vary in weight — that is why the shelf is weighed rather than counted — so no factor converts
 * the sold figure into the shelf's. {@see OrderItem::producedQuantity()} falls back to the sold
 * quantity when nothing was measured, which for such a line means five hundred *kilograms* would
 * leave a shelf holding five hundred pieces' worth, silently and with a movement to prove it.
 *
 * {@see TransitionFields} already demands the figure on the way into «جاهزة», so this is the
 * floor under that: a caller that skipped the form — a console command, an importer, a client
 * written before the field existed — gets a refusal rather than a wrong deduction.
 * {@see DeductOrderStock} throws it before a single line has left the warehouse.
 *
 * Reported against each line's own field, so the app puts the message on the box that is empty
 * rather than at the top of a form with four of them.
 */
final class LineNeedsAMeasuredStockQuantity extends DomainException
{
    /**
     * @param  non-empty-list<OrderItem>  $items
     */
    private function __construct(private readonly array $items)
    {
        parent::__construct(
            count($items) === 1
                ? self::sentence($items[0])
                : 'أدخل الكمية المخصومة من المخزن للمواد التالية'
        );
    }

    /**
     * @param  non-empty-list<OrderItem>  $items
     */
    public static function make(array $items): self
    {
        return new self($items);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        $errors = [];

        foreach ($this->items as $item) {
            $errors['fields.'.TransitionFields::stockQuantityKey($item)] = [self::sentence($item)];
        }

        return $errors;
    }

    private static function sentence(OrderItem $item): string
    {
        return "«{$item->variant_label}» يُخزَّن بال{$item->stockUnit()->label()}"
            ." ويُباع بال{$item->pricing_unit->label()} — أدخل الكمية التي تخرج من المخزن";
    }
}
