<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

/**
 * Which road an order walks — the second thing {@see OrderStatus::allowedNext()} reads.
 *
 * **A كيس سادة is not printed, and until now it had to pretend it was.** The map was one `match`
 * on the status alone, so every order in the shop went جديدة → قيد التصميم → قيد الطباعة →
 * جاهزة; a plain bag pulled off a shelf and counted had to be walked through two statuses naming
 * work nobody did. That is the same complaint `Order::designsAreEditable()` already answered for
 * artwork — staff pushing an order into the designer's queue and straight back out to record a
 * file — and it is answered the same way here: the road that does not apply is not offered.
 *
 * **Not a flag on the order, and not a second enum of statuses.** A boolean would have to be read
 * as «هل يتخطى؟» at four call sites and would say nothing about *what* is skipped; a parallel set
 * of statuses would fork the labels, the permissions and the timestamps for a road that differs
 * in two steps. This is the smallest thing that carries the whole answer: a name for the road,
 * passed to the map that already exists.
 *
 * **Where it comes from.** `ResolveOrderFlow` reads it off the lines' product categories at intake
 * and stamps it on the order — see that action for why the answer is snapshotted rather than
 * derived on every read.
 *
 * `label()` is not decoration: `AuditValueLabels` auto-translates any enum-cast column whose enum
 * can name itself, so the history screen prints «بلا تصميم وطباعة» without a second dictionary.
 */
enum OrderFlow: string
{
    /**
     * The road the whole shop walked before this enum existed, and still the common one:
     * the artwork is agreed, the press runs, and the bags exist afterwards.
     */
    case Standard = 'standard';

    /**
     * Goods that are already made. Nothing is designed and nothing is printed — the order is
     * picked off a shelf, counted, and it is «جاهزة».
     *
     * **It still deducts stock, and still asks where from.** Skipping production is not skipping
     * fulfilment: entering «جاهزة» takes the same warehouse, the same per-line weight and the
     * same costing it takes on the printed road — see `TransitionFields`.
     */
    case NoProduction = 'no_production';

    /**
     * **وسيط.** دعاية sold it and an outside vendor makes it: the order may be designed here
     * first, is then sent out, and comes back finished — جديدة → (قيد التصميم) → قيد التصنيع →
     * جاهزة. See OUTSOURCED-PRODUCTS.md.
     *
     * **It is the road that made {@see hasProduction()} unanswerable.** That method was a
     * boolean over two roads, and the third has work on it — the designer's — while having no
     * press and no shelf at all. So the map branches on the flow itself now, and the two
     * questions that were folded into one word are asked separately: {@see deductsStock()} for
     * the warehouse, and the arm in `OrderStatus::allowedNext()` for the road.
     */
    case Outsourced = 'outsourced';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'المسار المعتاد',
            self::NoProduction => 'بلا تصميم وطباعة',
            self::Outsourced => 'مسار الوسيط',
        };
    }

    /**
     * Whether anything leaves one of our shelves on this road.
     *
     * **Not the same question as «هل يوجد عمل؟», which is why it is its own method.** Goods that
     * are already made skip the press and are still picked, counted and deducted; goods a vendor
     * makes for us are never in our warehouse to begin with, so the move into «جاهزة» asks for no
     * warehouse and takes nothing out of one.
     *
     * Read in exactly two places — `ChangeOrderStatus`, which performs the deduction, and
     * `TransitionFields`, which asks for what it needs — so what is requested on the screen and
     * what is done to the shelf cannot drift apart.
     */
    public function deductsStock(): bool
    {
        return $this !== self::Outsourced;
    }

    /**
     * Whether the order has to say who is making it.
     *
     * The other side of the same coin as {@see deductsStock()}: work that leaves the building has
     * somebody's name on it, and «أين ذهبت الطلبية؟» is unanswerable afterwards if nobody was
     * named at the time. Enforced by `CreateOrder` once the road is known — it cannot be a rule on
     * the request, which sees product ids rather than a road.
     */
    public function needsAVendor(): bool
    {
        return $this === self::Outsourced;
    }
}
