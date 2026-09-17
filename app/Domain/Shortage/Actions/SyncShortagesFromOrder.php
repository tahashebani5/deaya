<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Actions;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Order\DTOs\OrderLineShortage;
use App\Domain\Order\Enums\ShortageRevision;
use App\Domain\Order\OrderService;
use App\Domain\Shortage\Enums\ShortageSource;
use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Enums\SupplyKind;
use App\Domain\Shortage\Models\Shortage;
use Illuminate\Support\Facades\DB;

/**
 * Brings the shortages section into line with what an order says is missing.
 *
 * **Declarative, not incremental — which is the whole design.** This does not apply a change; it
 * states what should be true given the order as it stands right now, and makes it so. Running it
 * twice therefore changes nothing, and that is not a nicety: recording a supply writes back to
 * the order line, the write fires `OrderShortagesRecorded`, and the event runs this. An
 * incremental version would double every purchase on its own second pass.
 *
 * **The formula, and why `required` is not simply the line's number:**
 *
 *     required_quantity = order_item.shortage_quantity + Σ supplied_quantity
 *
 * A supply reduces `shortage_quantity` — that is what puts the goods back on the customer's
 * invoice — so a requirement read straight off the line would shrink every time somebody filled
 * part of the gap. «المتبقي» would be zero forever and partial supply, the case §٥ of the brief
 * is mostly about, would be invisible. Adding back what has already been supplied pins the
 * requirement at what was originally missing.
 *
 * **Four things can happen to a line, and all four are here:**
 *
 * | the line says | and the section holds | so |
 * |---|---|---|
 * | short by something | nothing | create |
 * | short by something | a shortage | restate its requirement |
 * | nothing missing, goods received | any shortage | record what arrived, and complete it |
 * | nothing missing, a correction | one nobody ever touched | soft-delete it — the claim is withdrawn |
 *
 * The third row is the one that is easy to miss. A colleague can resolve the same shortage from
 * the **order** screen, by typing what arrived into `received_{itemId}` when the order leaves
 * «نواقص». Without a row written for that, `required_quantity` would shrink on its own and the
 * log would show a shortage that closed with nothing recorded against it — see {@see SupplyKind}.
 *
 * **A line that disappears off the order is not handled here**, because this only ever sees the
 * lines that still exist. {@see CloseShortagesForOrder} is what sweeps the rest, called from the
 * same listener.
 */
final class SyncShortagesFromOrder
{
    public function __construct(
        // Through the module's front door, never `OrderItem::query()` — the seam RULES §3 asks
        // for, and the one that lets Order change its lines without this having to be told.
        private readonly OrderService $orders,
        private readonly RecalculateShortageTotals $recalculate,
        private readonly CloseShortagesForOrder $closeOrphans,
    ) {}

    public function __invoke(
        int $orderId,
        ShortageRevision $reason = ShortageRevision::Corrected,
        ?int $actorId = null,
    ): void {
        $lines = $this->orders->shortageLinesFor($orderId);

        if ($lines === []) {
            return;
        }

        DB::transaction(function () use ($orderId, $lines, $reason, $actorId): void {
            foreach ($lines as $line) {
                $this->reconcile($line, $reason, $actorId);
            }

            // Lines deleted off the order since the last sync leave shortages behind that no
            // line will ever mention again — they have to be swept by elimination rather than
            // met in the loop above.
            ($this->closeOrphans)(
                $orderId,
                array_map(static fn (OrderLineShortage $line): int => $line->lineId, $lines),
            );
        });
    }

    private function reconcile(OrderLineShortage $line, ShortageRevision $reason, ?int $actorId): void
    {
        // `withTrashed()` deliberately absent: a shortage somebody deleted by hand stays deleted,
        // and the partial unique index is scoped the same way, so a line that is short again
        // gets a fresh row rather than resurrecting one an employee threw away.
        $shortage = Shortage::query()
            ->where('order_item_id', $line->lineId)
            ->lockForUpdate()
            ->first();

        $missing = $line->shortageQuantity ?? '0';

        if ($shortage === null) {
            if (bccomp($missing, '0', 3) > 0) {
                $this->create($line, $missing, $actorId);
            }

            return;
        }

        /*
         * **«لم يعد ناقصاً» has two meanings, and the numbers alone cannot tell them apart.**
         *
         * A line falling from short-by-thirty to short-by-nothing is either «وصلت البضاعة» — the
         * clerk typing 30 into `received_{id}` as the order leaves «نواقص» — or «لا ينقص منها
         * شيء», a correction on the order form. Both reach here as the same number and deserve
         * opposite endings: the first is history worth keeping, the second is a row that should
         * never have existed. Recording an arrival for a slip would put «مكتمل ٣٠ كجم» on the
         * board for goods nobody ever procured.
         *
         * **The writer says which, and says it as a fact rather than a guess** — see
         * {@see ShortageRevision}. Reading it off the order's status was the alternative and it
         * was subtly wrong: whether the status had moved by the time this ran depended on whether
         * the listener fired inside the request or off a queue, so the same event meant different
         * things on a laptop and on a server.
         *
         * **The shortage's own state is the second half of the test**, because a correction is
         * only safely a slip if nobody had made it their own: still «جديد», in nobody's queue,
         * nothing recorded against it. Anything else has had a person's hands on it, and their
         * work is not thrown away on an inference.
         *
         * Soft-deleted either way, so a wrong reading is recoverable and the trail keeps the row.
         */
        if (bccomp($missing, '0', 3) <= 0
            && $reason !== ShortageRevision::Received
            && $this->wasNeverChased($shortage)) {
            $shortage->delete();

            return;
        }

        // **What arrived through the order screen, if anything did.** The requirement has not
        // moved, the line's missing quantity fell, and no purchase was recorded here — so the gap
        // between the two is goods that turned up by the other road. See SupplyKind.
        $arrived = bcsub(
            bcsub((string) $shortage->required_quantity, $missing, 3),
            (string) $shortage->supplied_quantity,
            3,
        );

        if (bccomp($arrived, '0', 3) > 0) {
            // `forceFill` because `kind` and `recorded_by_user_id` are stamped rather than
            // fillable — a payload that could set either could write a purchase in somebody
            // else's name, or an arrival that pretends no money was owed.
            $supply = $shortage->supplies()->make();

            $supply->forceFill([
                'kind' => SupplyKind::ResolvedExternally,
                'quantity' => $arrived,
                'occurred_on' => now()->toDateString(),
                'recorded_by_user_id' => $actorId,
            ])->save();
        }

        /*
         * The formula, and it is computed **after** the arrival is written rather than before.
         *
         * `required = missing + Σ supplied` only holds once the ledger reflects everything that
         * has come back. Read a moment earlier, a shortage whose goods all arrived through the
         * order would compute a requirement of zero — which the CHECK on the table refuses, and
         * rightly: «نقص مطلوبه صفر» is not a thing. Written afterwards it comes out at the
         * thirty that really was chased, with thirty supplied against it.
         *
         * Through `liveSuppliedQuantity()` and never `supplies()->sum()`: a reversal is a row with
         * a positive quantity, so summing the table would count a purchase and its undoing as two
         * arrivals and inflate the requirement every time an entry was corrected.
         */
        $required = bcadd($missing, $shortage->liveSuppliedQuantity(), 3);

        $shortage->forceFill([
            'name' => $line->name,
            'unit' => $line->unit,
            'required_quantity' => $required,
        ])->save();

        // Always, not only when a row was written: the requirement above may have grown, which
        // reopens a shortage that had been reading «مكتمل».
        ($this->recalculate)($shortage->refresh());
    }

    /**
     * Whether anybody ever treated this shortage as work of their own.
     *
     * Untouched means all three: still «جديد», in nobody's queue, and with nothing recorded
     * against it. Any one of them being false means a person has had their hands on it — see the
     * block in {@see reconcile()} that asks.
     */
    private function wasNeverChased(Shortage $shortage): bool
    {
        return $shortage->status === ShortageStatus::New
            && $shortage->assigned_to_user_id === null
            && ! $shortage->supplies()->exists();
    }

    private function create(OrderLineShortage $line, string $missing, ?int $actorId): void
    {
        // `forceFill` throughout: everything an order-born shortage is made of is copied from the
        // line, and none of it is fillable — see the model, where the fillable list is the manual
        // form's fields and nothing else.
        $shortage = new Shortage;

        $shortage->forceFill([
            'source' => ShortageSource::FromOrder,
            'order_id' => $line->orderId,
            'order_item_id' => $line->lineId,
            'customer_id' => $line->customerId,
            'product_id' => $line->productId,
            'product_variant_id' => $line->productVariantId,
            // Snapshotted, like everything a person reads on an old record — see the model.
            'name' => $line->name,
            'unit' => PricingUnit::from($line->unit),
            'required_quantity' => $missing,
            // Explicit for the reason CreateShortage gives: an unsaved model does not read the
            // column defaults back, and this row is handed straight to the recalculation.
            'supplied_quantity' => '0.000',
            'total_paid' => '0.00',
            'status' => ShortageStatus::New,
            // **Unassigned, on purpose.** An order that lands in «نواقص» at two in the morning
            // belongs to nobody until somebody picks it up, and quietly assigning it to whoever
            // moved the order would put work in a queue they never agreed to take.
            'assigned_to_user_id' => null,
            'created_by_user_id' => $actorId,
        ])->save();
    }
}
