<?php

declare(strict_types=1);

namespace App\Domain\Vendor\Actions;

use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\Exceptions\ArrivalBatchAlreadyDrawnOn;
use App\Domain\Inventory\Exceptions\ArrivalBatchWasRevalued;
use App\Domain\Inventory\InventoryService;
use App\Domain\Vendor\DTOs\ReverseStockArrivalData;
use App\Domain\Vendor\Exceptions\StockArrivalAlreadyReversed;
use App\Domain\Vendor\Exceptions\StockArrivalReversalWindowClosed;
use App\Domain\Vendor\Models\StockArrival;
use App\Domain\Vendor\Models\StockArrivalItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Takes a shipment back off the shelf because it was entered in error, and marks the document
 * saying so.
 *
 * **The mirror of {@see RecordStockArrival}, and it never writes a balance either.** Each line
 * becomes its own `arrival_reversal` ledger row through
 * {@see InventoryService::recordMovement()} — the one path stock is ever allowed to move
 * through — pointing at the `purchase_arrival` movement it undoes. One transaction wraps the
 * whole document, so a three-line receipt is taken back in full or not at all.
 *
 * **Nothing is deleted.** The arrival, its lines, the movements it produced and the cost layers
 * it opened all stay exactly where they are; what changes is that three columns on the document
 * now say it was a mistake, and each layer has been drawn to nothing by a movement that names
 * the receipt it undoes. Re-receiving is a *new* arrival, not an un-reversal of this one.
 *
 * ## What it refuses, and which refusal a manager may step past
 *
 * Exactly one of the four is policy:
 *
 * - **Already reversed** — {@see StockArrivalAlreadyReversed}. Refused for everybody.
 * - **Anything drawn from the layers** — {@see ArrivalBatchAlreadyDrawnOn}. Refused for
 *   everybody: the units that left are already costed into an order and no reversal reaches
 *   them. The correction past that point is a stocktake adjustment that writes the difference
 *   off, not a rewrite of a receipt that demonstrably happened.
 * - **A layer repriced by hand** — {@see ArrivalBatchWasRevalued}. Refused for everybody:
 *   somebody's deliberate judgement would disappear with the layer.
 * - **Past 24 hours** — {@see StockArrivalReversalWindowClosed}. **Waived** by
 *   `ReverseStockArrivalData::$ignoreWindow`, which the boundary sets from the caller's own
 *   grant. A layer nobody has touched is as safe to withdraw on the third day as on the first,
 *   so the clock is a rule about owning up to mistakes promptly — a thing a manager may
 *   override, with a reason on the record — rather than a rule about the ledger.
 *
 * @throws StockArrivalAlreadyReversed
 * @throws StockArrivalReversalWindowClosed
 * @throws ArrivalBatchAlreadyDrawnOn
 * @throws ArrivalBatchWasRevalued
 */
final class ReverseStockArrival
{
    /**
     * How long an ordinary receipt may be taken back in.
     *
     * A constant rather than a company setting, deliberately: it is a fixed policy today, and a
     * settings row would be a number somebody has to maintain to answer a question nobody has
     * asked twice. When the business does want to vary it, this is the one line that moves —
     * and {@see windowClosesAt()} is already the single place anything reads it from, including
     * the resource that publishes the deadline to the screen.
     */
    private const WINDOW_HOURS = 24;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * When this receipt stops being reversible by the person who posted it.
     *
     * **Anchored to the receipt, never to the purchase order it fulfils.** The receipt is what
     * moved the stock, and there is nothing to restore before it — an order issued a fortnight
     * before its lorry turned up would otherwise arrive with its window already shut.
     */
    public static function windowClosesAt(StockArrival $arrival): Carbon
    {
        return $arrival->created_at->copy()->addHours(self::WINDOW_HOURS);
    }

    public static function isWithinWindow(StockArrival $arrival): bool
    {
        return Carbon::now()->lessThanOrEqualTo(self::windowClosesAt($arrival));
    }

    public function __invoke(StockArrival $arrival, ReverseStockArrivalData $data): StockArrival
    {
        // Read before the transaction opens: both refusals cost nothing and hold a lock for
        // nothing. The guards that need the batches are inside, where they are locked.
        if ($arrival->isReversed()) {
            throw StockArrivalAlreadyReversed::make((int) $arrival->getKey());
        }

        if (! $data->ignoreWindow && ! self::isWithinWindow($arrival)) {
            throw StockArrivalReversalWindowClosed::make(self::windowClosesAt($arrival));
        }

        return DB::transaction(function () use ($arrival, $data): StockArrival {
            $arrival->loadMissing('items');

            foreach ($arrival->items as $item) {
                $this->withdrawLine($arrival, $item, $data);
            }

            // Assigned rather than mass-assigned: none of the three is fillable, precisely so
            // that no payload can reach them — the same rule `vendor_id` and `received_by`
            // follow. Stamped after the stock has actually gone, so a refusal anywhere above
            // leaves a document that still says, truthfully, that the shipment stands.
            $arrival->reversed_at = Carbon::now();
            $arrival->reversed_by = $data->reversedBy;
            $arrival->reversal_reason = $data->reason;
            $arrival->save();

            return $arrival;
        });
    }

    /**
     * One line back off the shelf, through the only door stock ever moves through.
     *
     * `reversedMovementId` is what makes this a withdrawal of *these* layers rather than a FIFO
     * draw on the oldest stock in the building — see `WithdrawArrivalStockBatches`. It is also
     * persisted, and the partial UNIQUE behind that column is what turns a second reversal of
     * the same line into a database error rather than a silently halved shelf.
     */
    private function withdrawLine(StockArrival $arrival, StockArrivalItem $item, ReverseStockArrivalData $data): void
    {
        $this->inventory->recordMovement(StockMovementData::arrivalReversal(
            stockItemId: (int) $item->stock_item_id,
            warehouseId: (int) $arrival->warehouse_id,
            // What the line brought in. The quantity actually taken back is decided by the
            // layers themselves, under their own lock — this is the ledger row's own figure,
            // and the two agree by construction because a layer nobody has drawn on still holds
            // exactly what the arrival put on it.
            quantity: (string) $item->quantity,
            reversedMovementId: (int) $item->stock_movement_id,
            referenceId: (int) $arrival->getKey(),
            employeeId: $data->reversedBy,
        ));
    }
}
