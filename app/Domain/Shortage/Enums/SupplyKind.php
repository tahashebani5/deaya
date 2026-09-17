<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Enums;

use App\Domain\Shortage\Actions\SyncShortagesFromOrder;

/**
 * How a quantity came back — bought, or simply turned up.
 *
 * **The reason the second case exists is that the shortage has two doors.** An employee closes it
 * from this section by recording what they bought; a colleague closes the same shortage from the
 * order screen, by typing what arrived into `received_{itemId}` when the order leaves «نواقص».
 * Both are real and both stay open (SHORTAGES-DESIGN §٣٫١).
 *
 * Without a row for the second, `required_quantity` would shrink on its own and the log would
 * show a shortage that closed with nothing ever recorded against it — «توفّر ٣٠ كجم» with no
 * line saying so. {@see ResolvedExternally} is that line: quantity only, written by
 * {@see SyncShortagesFromOrder}, never by a request.
 *
 * **Which is also why money is required on one and refused on the other.** «الكمية + القيمة +
 * طريقة الدفع» is a rule about a purchase somebody made; goods that arrived from a delivery
 * already paid for on a purchase order have no second price, and inventing a zero for them would
 * put a free purchase in a total that answers «كم صرفنا على النواقص؟».
 */
enum SupplyKind: string
{
    /** Bought to close the shortage. Carries quantity, amount and payment method. */
    case Purchased = 'purchased';

    /** Arrived by another road — quantity only, and the system is its only author. */
    case ResolvedExternally = 'resolved_externally';

    public function label(): string
    {
        return match ($this) {
            self::Purchased => 'شراء',
            self::ResolvedExternally => 'وصلت من الطلبية',
        };
    }

    /**
     * Whether this kind must carry what it cost and how it was paid.
     *
     * Stated here rather than in the FormRequest so the rule holds for a console command and an
     * importer too. The request repeats it, which is what produces the readable field-level 422;
     * the database repeats it a third time as a CHECK, which is what actually guarantees it —
     * the same three-layer arrangement `PaymentMethod::requiresReceipt()` already uses.
     */
    public function requiresPayment(): bool
    {
        return $this === self::Purchased;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind) => $kind->value, self::cases());
    }
}
