<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

use App\Domain\Inventory\Models\StockMovement;

/**
 * Why a quantity moved.
 *
 * The two predicates below are the whole shape rule of the ledger, kept in one place. Four
 * separate FormRequests each declare which warehouse fields they take, and it would be easy for
 * those four declarations to be the only statement of the rule — at which point the fifth
 * caller (a console command, a seeder, an importer) gets it wrong silently. Stating it on the
 * enum means {@see StockMovement} can be checked against its own type wherever it is built.
 */
enum MovementType: string
{
    /** Stock entering the business from a supplier. */
    case PurchaseArrival = 'purchase_arrival';

    /** Stock moving between two of our own warehouses. */
    case InternalTransfer = 'internal_transfer';

    /** Stock leaving for a customer's order. */
    case OrderFulfillment = 'order_fulfillment';

    /** A stocktake correction — the shelf disagreed with the book. */
    case Adjustment = 'adjustment';

    /**
     * Stock credited back after a cancelled order's fulfillment — the exact cost layers the
     * original {@see OrderFulfillment} drew from, not a fresh one at an averaged cost. Its own
     * type rather than a reuse of `Adjustment`: an operator correcting a miscount and the system
     * undoing a cancelled order's deduction are different events, and a report should be able to
     * tell them apart without inspecting `reference_id`.
     */
    case OrderReversal = 'order_reversal';

    /**
     * Stock destroyed during production — a misprint, a spoiled run — rather than sold or moved.
     * Source-only, the same shape as {@see OrderFulfillment}: it leaves the warehouse and does
     * not arrive anywhere else. Its own type rather than a reuse of `Adjustment`: this is
     * production waste with a real cost that lands on the order's own cost ledger, in the Order
     * context (`RecordScrapLoss`), not an operator's stocktake correction.
     */
    case ScrapLoss = 'scrap_loss';

    /**
     * Stock taken back off the shelf because the receipt that put it there was entered in
     * error — the wrong quantity typed, the wrong order received against. Withdraws the exact
     * cost layers the {@see PurchaseArrival} opened, at their own cost, rather than drawing FIFO
     * from the oldest layers on the shelf: the layers this undoes are the erroneous ones, and
     * consuming somebody else's oldest stock instead would leave the mistake sitting on the
     * shelf while quietly repricing goods that were never in question.
     *
     * Its own type rather than a reuse of `Adjustment`: an operator correcting a miscount and
     * the system unwinding a receipt somebody posted by mistake are different events, and a
     * report should be able to tell them apart without inspecting `reference_id`. The mirror of
     * {@see OrderReversal}, which undoes a draw rather than an entry.
     */
    case ArrivalReversal = 'arrival_reversal';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseArrival => 'توريد',
            self::InternalTransfer => 'تحويل داخلي',
            self::OrderFulfillment => 'صرف لطلب',
            self::Adjustment => 'تسوية جرد',
            self::OrderReversal => 'إرجاع بعد إلغاء طلبية',
            self::ScrapLoss => 'تلف أثناء الإنتاج',
            self::ArrivalReversal => 'إلغاء استلام شحنة',
        };
    }

    /**
     * Whether this kind of movement takes stock *out* of a warehouse.
     *
     * An adjustment answers neither this nor {@see requiresDestination()} definitively: it takes
     * exactly one side, and which one depends on its {@see AdjustmentDirection}. So both return
     * false for it, and the action asks the direction instead.
     */
    public function requiresSource(): bool
    {
        return match ($this) {
            self::InternalTransfer, self::OrderFulfillment, self::ScrapLoss,
            self::ArrivalReversal => true,
            self::PurchaseArrival, self::Adjustment, self::OrderReversal => false,
        };
    }

    /**
     * Whether this kind of movement puts stock *into* a warehouse.
     */
    public function requiresDestination(): bool
    {
        return match ($this) {
            self::PurchaseArrival, self::InternalTransfer, self::OrderReversal => true,
            self::OrderFulfillment, self::Adjustment, self::ScrapLoss,
            self::ArrivalReversal => false,
        };
    }

    /**
     * Whether the side this movement uses is decided by a direction rather than by the type.
     */
    public function isDirectional(): bool
    {
        return $this === self::Adjustment;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
