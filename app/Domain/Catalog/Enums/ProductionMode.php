<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Order\Exceptions\OutsourcedLineCannotShareAnOrder;

/**
 * How goods under a heading come to exist — the lever an order's road is decided by.
 *
 * **It was a boolean, and «وسيط» is what it could no longer answer.** `skips_production` asked
 * «هل يُطبع؟» and had two answers because the shop had two kinds of work: what it prints, and
 * what it pulls off a shelf. A product دعاية sells but does not make is a third: it is designed
 * here often enough, it is never printed here, and it never sits on our shelf at all. A second
 * boolean beside the first would have made «سادة ووسيط» expressible and meaningless, so the one
 * column grew a vocabulary instead.
 *
 * **On the category, not on the product**, exactly as the boolean was. «النوع» was a column on
 * the product once — مطبوعة/سادة — and PRODUCT-CATEGORIES.md records why it became headings
 * instead: one list, edited once, rather than a word retyped per product forever. وسيط joins that
 * list rather than reopening the argument.
 *
 * **This enum knows nothing about orders**, and that is deliberate: `ResolveOrderFlow` maps a
 * mode to an `OrderFlow` — named in prose rather than in a `{@see}`, because even a docblock
 * reference would put an Order import at the top of a Catalog file. Catalog stays ignorant of
 * Order, and the dependency keeps running one way; RULES.md §3.
 *
 * `label()` is not decoration: `AuditValueLabels` auto-translates any enum-cast column whose enum
 * can name itself, so the category's history prints Arabic without a second dictionary.
 *
 * @see ProductCategory::productionMode() for how a heading answers on its children's behalf
 */
enum ProductionMode: string
{
    /** The road the whole shop walked before any of this: we agree the artwork and we print it. */
    case InHouse = 'in_house';

    /**
     * Goods that are already made — «سادة». Nothing is designed and nothing is printed; the
     * order is picked off a shelf, counted, and it is «جاهزة».
     */
    case None = 'none';

    /**
     * **وسيط.** دعاية sells it, an outside vendor makes it. There may be artwork to agree first,
     * there is never a press of ours, and there is never a shelf of ours — which is why this is
     * the one mode that deducts no stock. See OUTSOURCED-PRODUCTS.md.
     */
    case Outsourced = 'outsourced';

    /**
     * Which orders this mode may share.
     *
     * **Two groups, and the line between them is not «how much work» but «whose».** «سادة» and
     * «مطبوعة» are both ours — one is pulled off a shelf and the other is printed, and an order
     * holding both walks the standard road perfectly well. «وسيط» is somebody else's bench: the
     * order goes out of the building and comes back, there is no press of ours in it and no
     * shelf of ours behind it, and `OrderFlow` has a whole road of its own for that. Mixing the
     * two would ask one order to walk two roads, and the order would quietly take the wrong one
     * — see {@see OutsourcedLineCannotShareAnOrder}.
     *
     * **This is the Catalog's half of an Order rule, and it is phrased so it stays the Catalog's
     * half.** The method answers a question about *this heading* — «مع من يُطلب؟» — and names no
     * order, no flow and no exception. `ResolveOrderFlow` maps it to a road; the client resource
     * sends it as an opaque token so the customer app can refuse a basket without ever learning
     * what «وسيط» is. Catalog stays ignorant of Order, RULES.md §3.
     */
    public function orderGroup(): string
    {
        return match ($this) {
            self::InHouse, self::None => 'shared',
            self::Outsourced => 'exclusive',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::InHouse => 'تصميم وطباعة لدينا',
            self::None => 'بلا تصميم وطباعة',
            self::Outsourced => 'وسيط — لدى مورد خارجي',
        };
    }

    /**
     * Whether a *cost price* is a meaningful thing to record on a product filed here.
     *
     * Only for وسيط, and the reason is that a cost has to have one owner. What we make ourselves
     * is costed from the materials it consumed and the rates it was charged —
     * `order_items.material_cost` and `production_cost_entries` — and a second number typed onto
     * the catalogue would be an unowned answer to a question already answered properly.
     */
    public function hasCostPrice(): bool
    {
        return $this === self::Outsourced;
    }
}
