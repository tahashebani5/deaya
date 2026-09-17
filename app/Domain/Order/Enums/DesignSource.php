<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

/**
 * Who is responsible for the artwork on an order — and therefore whether we charge for it.
 *
 * Deliberately **not** the same question as where a given file came from. Every design an order
 * uses is picked from the customer's library, including the ones we drew ourselves, because the
 * artwork is the customer's property and belongs on their record rather than buried in one
 * order. So "which file is this" is answered by `order_designs.customer_design_id`, and this
 * enum answers "whose work was it" — the only one of the two that may move money.
 */
enum DesignSource: string
{
    /** A repeat print of something already agreed, or plain bags with nothing on them. */
    case None = 'none';

    /** The customer supplied finished artwork. */
    case Customer = 'customer';

    /** We drew it. The one case that adds `design_fee` to the total. */
    case InHouse = 'in_house';

    public function label(): string
    {
        return match ($this) {
            self::None => 'بدون تصميم',
            self::Customer => 'تصميم العميل',
            self::InHouse => 'تصميم من عندنا',
        };
    }

    /** Whether a design fee may be charged. Only our own work. */
    public function isChargeable(): bool
    {
        return $this === self::InHouse;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $source) => $source->value, self::cases());
    }
}
