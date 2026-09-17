<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Order\Actions\RecordPartialDelivery;
use App\Domain\Order\Models\OrderItem;

/**
 * What became of the part of a line the customer did not take.
 *
 * **Two cases, because the goods themselves only have two futures.** Plain bags off a shelf are
 * ordinary saleable stock and go back on it; bags carrying this customer's artwork are worth
 * nothing to anybody else and are a loss. Nothing in between exists — a third case would have to
 * name a buyer, and there isn't one.
 *
 * **Decided from {@see ProductionMode}, then stamped.** {@see RecordPartialDelivery} reads
 * {@see OrderItem::isPrinted()} once, at the moment of delivery, and writes the answer down. It
 * is deliberately *not* re-derived on every read: that method answers from the category as it
 * stands **now**, so a product re-filed next year would silently rewrite a delivery from last
 * March. Renaming a product must not rewrite an invoice issued last year — `product_name` and
 * `variant_label` are copied onto the line for exactly that reason — and re-filing one must not
 * rewrite a delivery either.
 *
 * **Asked of the line, never of the order.** `ResolveOrderFlow` puts a whole order on the printed
 * road for one printed line among five plain ones, which is right for the road and wrong for the
 * goods: a mixed order restocks its plain lines and writes off its printed ones in the same move.
 *
 * `label()` is not decoration: `AuditValueLabels` auto-translates any enum-cast column whose enum
 * can name itself, so the order's history prints Arabic without a second dictionary.
 */
enum UndeliveredDisposition: string
{
    /**
     * Back on the shelf — «سادة». The bags were never printed, so they are the same stock they
     * were before this order picked them up.
     *
     * The return is a *restatement* of the original draw rather than a fresh movement: see
     * `RestateOrderStockDeduction`, whose reverse-the-whole-movement-then-redraw-the-corrected-
     * quantity is the only partial credit Inventory has, and the reason there is no
     * `MovementType::PartialReturn`.
     */
    case Restocked = 'restocked';

    /**
     * A loss — printed here, or made for us by a وسيط vendor.
     *
     * **Two different reasons reaching one answer**, which is why this is not two cases. Bags
     * printed on our own press left our shelf at «جاهزة» and came back carrying artwork that
     * makes them unsellable. وسيط goods were never on a shelf of ours at all — see
     * `OrderFlow::deductsStock()` — so there is nothing to put back even in principle. Either
     * way nothing moves in the warehouse and what is recorded is a named `ProductionCostEntry`.
     */
    case WrittenOff = 'written_off';

    /**
     * Which road a line's leftover takes.
     *
     * **The one place the mapping lives.** `RecordPartialDelivery` calls it, `TransitionFields`
     * calls it to write the hint that tells the person at the counter what is about to happen,
     * and the two therefore cannot come to disagree about what the button does — the same
     * discipline `TransitionFields::deductionPreview()` keeps with `DeductOrderStock`.
     *
     * **Read from {@see OrderItem::productionMode()}, deliberately not from
     * {@see OrderItem::isPrinted()}.** The two look interchangeable and are not: `isPrinted()`
     * asks «does *our* press run on this line?» — the fork سعر السادة turns on — and answers
     * **false** for وسيط, whose goods a vendor prints. Using it here would send a vendor's
     * printed bags back to a shelf they were never on and that cannot hold them.
     *
     * The question this enum asks is the other one: «can anybody else buy these?» Only سادة
     * answers yes.
     *
     * The unknown case follows `isPrinted()` and `ResolveOrderFlow` in treating a product filed
     * under no heading as production work — but the *generous* direction is the opposite one
     * here. There it prices material at what the press pays, which favours the investor; here
     * `WrittenOff` is the answer that does not put goods on a shelf nobody can sell them off.
     * A wrongly restocked line is phantom stock somebody later has to write off by hand; a
     * wrongly written-off one is a loss the shop can see and correct.
     *
     * Callers must eager-load `product.productCategory.parent`; strict mode turns a forgotten
     * load into an exception rather than a query per line.
     */
    public static function forItem(OrderItem $item): self
    {
        return $item->productionMode() === ProductionMode::None
            ? self::Restocked
            : self::WrittenOff;
    }

    /** Whether goods disposed of this way go back on a shelf of ours. */
    public function returnsToStock(): bool
    {
        return $this === self::Restocked;
    }

    public function label(): string
    {
        return match ($this) {
            self::Restocked => 'أُعيد إلى المخزن',
            self::WrittenOff => 'خسارة',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
