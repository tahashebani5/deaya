<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\DiscountExceedsTotal;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

/**
 * Takes the delivery fee back out of the totals of orders that are still running.
 *
 * The fee left `grand_total` on 2026-09-08 by the owner's instruction — «سعر التوصيل ليس من
 * تكاليفي وليس حتى من ارباحي، هو فقط على الزبون» — see {@see RecalculateOrderTotals}. Orders taken
 * before that day are still carrying it, and this is the repair for the ones that are still alive.
 *
 * **An in-flight order cannot be left carrying it, and that is what makes this urgent rather than
 * tidy.** {@see \App\Domain\Carrier\Actions\BuildNawrisPayload::amountToCollect()} no longer
 * subtracts the fee, because nothing adds it any more. On an order whose total still contains it,
 * the next edit of the parcel
 * would ask Nawris to collect the fee *and* leave the courier charging it at the door — the
 * double-charge the old arrangement existed to prevent, made once more from the other side.
 *
 * **The repair also lands exactly on the COD the courier is already holding.** A parcel dispatched
 * under the old rule was sent `remaining − delivery_price`; the same order recomputed here has a
 * remainder smaller by exactly that fee, so the figure Nawris holds is right without a single call
 * to them. Nothing outside this database is touched.
 *
 * **Closed orders are refused, deliberately.** «تم الاستلام» and «تم التسوية» record what was
 * billed and what came back, and we really did bill the fee: rewriting those totals would make the
 * order disagree with the receipt in the customer's hand and with the cash that was counted.
 * Money that genuinely needs correcting on a closed order is the investors' share of it — posted
 * out of `grand_total − total_cogs` by
 * {@see \App\Domain\Investor\Actions\PostDealEarningsForOrder} — and a ledger is corrected with a
 * reversal entry, never by rewriting the figure it was computed from. That is a separate,
 * deliberate piece of work and this command does not pretend to do it.
 *
 * Cancelled orders are skipped for a simpler reason: nothing will ever be collected on one, so
 * there is no figure on it worth moving.
 *
 * **Two orders it names rather than fixes quietly**, because only a person can settle them:
 *
 *   - one whose discount no longer fits the narrower base — 115 off 120 was agreed when the trip
 *     was part of the bill, and shrinking it to fit would charge the customer more than they were
 *     promised. Left exactly as it stands.
 *   - one whose deposit now exceeds the whole order — the customer has already paid us for a trip
 *     the courier is about to charge them for again. Corrected, then named, so somebody decides
 *     whether the difference goes back.
 *
 * Rows that need nothing are not written to, so `updated_at` still says when a person last changed
 * the order. Re-running it changes nothing.
 */
class DropDeliveryFromOrderTotals extends Command
{
    use ConfirmableTrait;

    protected $signature = 'orders:drop-delivery-from-totals
                            {--dry-run : Report what would change and write nothing}
                            {--force : Skip the confirmation prompt outside local}';

    protected $description = 'Recompute open orders so the delivery fee is no longer inside grand_total';

    /**
     * The statuses whose figures are finished. See the class docblock for why each is here.
     *
     * @var list<OrderStatus>
     */
    private const CLOSED = [
        OrderStatus::Delivered,
        OrderStatus::Settled,
        OrderStatus::Cancelled,
    ];

    public function handle(RecalculateOrderTotals $recalculate): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $corrected = 0;
        $moved = '0.00';

        /** @var list<array{code: string, note: string}> $refused */
        $refused = [];
        /** @var list<array{code: string, note: string}> $overpaid */
        $overpaid = [];

        Order::query()
            ->whereNotIn('status', array_map(fn (OrderStatus $s) => $s->value, self::CLOSED))
            ->where('delivery_price', '>', 0)
            ->orderBy('id')
            ->each(function (Order $order) use (
                $recalculate,
                $dryRun,
                &$corrected,
                &$moved,
                &$refused,
                &$overpaid,
            ): void {
                $before = (string) $order->grand_total;
                $after = $this->wouldBe($order);

                // Asked before the action rather than caught out of it. `RecalculateOrderTotals`
                // refuses a discount that no longer fits — rightly — and this is the one caller
                // that has to survive the refusal and carry on down the list.
                if ($after === null) {
                    $refused[] = [
                        'code' => (string) $order->code,
                        'note' => 'الخصم '.$order->discount.' أكبر من الإجمالي بعد رفع التوصيل',
                    ];

                    return;
                }

                if (bccomp($before, $after, Money::SCALE) === 0) {
                    return;
                }

                if (! $dryRun) {
                    // The one place that knows what an order costs, asked the question again now
                    // that its answer has changed. The line above is the same arithmetic asked
                    // without writing; this is the answer that counts.
                    $after = (string) ($recalculate)($order)->grand_total;
                }

                $corrected++;
                $moved = bcadd($moved, bcsub($before, $after, Money::SCALE), Money::SCALE);

                $remaining = $dryRun
                    ? bcsub($after, (string) $order->paid_amount, Money::SCALE)
                    : $order->fresh()->remainingAmount();

                if (bccomp($remaining, '0', Money::SCALE) < 0) {
                    $overpaid[] = [
                        'code' => (string) $order->code,
                        'note' => 'المدفوع يتجاوز الإجمالي بـ '.ltrim($remaining, '-'),
                    ];
                }
            });

        $this->report($dryRun, $corrected, $moved, $refused, $overpaid);

        return self::SUCCESS;
    }

    /**
     * What {@see RecalculateOrderTotals} would write, without writing it — and `null` where it
     * would refuse, because the discount no longer fits the narrower base.
     *
     * Deliberately the same arithmetic and deliberately not shared with it. A `--dry-run` that
     * called the real action inside a rolled-back transaction would still fire its model events,
     * and this has to be safe to point at a live database; asking the question here also lets the
     * run report the orders it cannot fix and keep going, which catching
     * {@see DiscountExceedsTotal} out of the action would buy at the cost of the pattern the whole
     * codebase keeps — see `ErrorHandlingTest`.
     *
     * **`items_total` is read off the row rather than re-summed from the lines.** The action does
     * re-sum them, and on an order whose lines have not moved the two agree; where they would not,
     * the action's answer is the one that gets written, and this one was only ever deciding
     * whether to call it.
     */
    private function wouldBe(Order $order): ?string
    {
        $designFee = $order->design_source->isChargeable() ? (string) $order->design_fee : '0.00';

        $base = Money::sum(
            (string) $order->items_total,
            $designFee,
            (string) $order->additional_cost,
        );

        if (bccomp((string) $order->discount, $base, Money::SCALE) > 0) {
            return null;
        }

        return Money::round(bcsub($base, (string) $order->discount, 8));
    }

    /**
     * @param  list<array{code: string, note: string}>  $refused
     * @param  list<array{code: string, note: string}>  $overpaid
     */
    private function report(
        bool $dryRun,
        int $corrected,
        string $moved,
        array $refused,
        array $overpaid,
    ): void {
        $this->info($dryRun
            ? "تجربة: {$corrected} طلبية سيُرفع عنها التوصيل، بمجموع {$moved}"
            : "تم: {$corrected} طلبية رُفع عنها التوصيل، بمجموع {$moved}");

        if ($refused !== []) {
            $this->newLine();
            $this->warn('طلبيات لم تُمس — خصمها لم يعد يسع الإجمالي، وتحتاج قراراً:');

            foreach ($refused as $row) {
                $this->line("  #{$row['code']} — {$row['note']}");
            }
        }

        if ($overpaid !== []) {
            $this->newLine();
            $this->warn('طلبيات صارت مدفوعة بالزيادة — الزبون دفع لنا التوصيل والمندوب سيطلبه منه:');

            foreach ($overpaid as $row) {
                $this->line("  #{$row['code']} — {$row['note']}");
            }
        }
    }
}
