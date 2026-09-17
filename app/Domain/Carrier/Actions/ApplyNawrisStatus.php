<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

use App\Domain\Carrier\DTOs\NawrisWebhookPayload;
use App\Domain\Carrier\Enums\NawrisStatusCode;
use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisWebhookEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Actions\RecordCarrierSettlement;
use App\Domain\Order\Actions\RecordOrderPayment;
use App\Domain\Order\DTOs\OrderPaymentData;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Everything a webhook is allowed to do to an order, and the three guards that stand in front.
 *
 * **This is the whole risk surface of the integration.** It is the only thing that moves an order
 * on the word of an unauthenticated POST, and the only thing that writes to the ledger without a
 * person deciding to. Every guard here exists because of an incident recorded in the contract;
 * reimplementing the mapping without them reimplements the incidents.
 *
 * The order matters and is not negotiable: **idempotency first, conflict second, price alert
 * third.**
 */
final class ApplyNawrisStatus
{
    public function __construct(
        private readonly ChangeOrderStatus $changeStatus,
        private readonly RecordOrderPayment $recordPayment,
        private readonly RecordCarrierSettlement $recordCarrierSettlement,
    ) {}

    public function __invoke(
        NawrisWebhookEvent $event,
        NawrisParcel $parcel,
        NawrisWebhookPayload $payload,
        User $actor,
    ): void {
        $code = $payload->status();

        // The raw label lands whatever happens — mapped, unmapped, legal or parked. It is what
        // support reads, and it is never interpreted.
        $this->recordCarrierState($parcel, $payload, $code);

        if ($code === null) {
            // An unmapped code must never guess at a state change. The label is stored; the note
            // says so; nothing moves.
            $event->forceFill(['error' => 'رمز حالة غير معروف: '.($payload->statusCode ?? 'null')])->save();

            return;
        }

        $target = $code->target();

        if ($target === null) {
            return;
        }

        foreach ($parcel->orders as $order) {
            $this->applyToOrder($event, $parcel, $payload, $code, $target, $order, $actor);
        }
    }

    private function applyToOrder(
        NawrisWebhookEvent $event,
        NawrisParcel $parcel,
        NawrisWebhookPayload $payload,
        NawrisStatusCode $code,
        OrderStatus $target,
        Order $order,
        User $actor,
    ): void {
        // ── guard 1: idempotency ─────────────────────────────────────────────────────────
        //
        // **Enum against enum, never against a string.** `orders.status` is a cast enum, and
        // comparing it to a plain string is always false — which leaves the guard passing
        // everything while looking like it works. That is the trap the contract names, and it is
        // silent, so it is worth the explicit type on both sides.
        if ($order->status === $target) {
            // Exception to the skip: a price discrepancy is still recorded, or a repeated
            // delivery notice would swallow the alert along with the duplicate.
            if ($code->collectsMoney()) {
                $this->flagDiscrepancy($event, $parcel, $payload);
            }

            return;
        }

        // ── guard 2: delivery conflict ───────────────────────────────────────────────────
        if ($code->collectsMoney() && $this->conflictBlocks($parcel, $payload)) {
            $parcel->forceFill(['conflict_raised_at' => now(), 'conflict_resolved_at' => null])->save();

            $event->forceFill([
                'error' => 'تعارض تسليم: رقم طرد مختلف ومبلغ غير مطابق — لم تُنقل الطلبية',
            ])->save();

            return;
        }

        // ── the machine still decides ────────────────────────────────────────────────────
        //
        // Our return chain is walked one link at a time on purpose; Nawris does not walk it. A
        // move it will not accept is parked rather than forced, because forcing it would record a
        // hand-over that never happened. See NAWRIS-INTEGRATION.md §3.2.
        if (! $order->status->canMoveTo($target, $order->production_flow)) {
            $event->forceFill([
                'error' => "نقلة غير مسموحة: «{$order->status->label()}» ← «{$target->label()}»",
            ])->save();

            return;
        }

        DB::transaction(function () use ($event, $parcel, $payload, $code, $target, $order, $actor): void {
            // ── guard 3: price discrepancy — alert, never block ──────────────────────────
            if ($code->collectsMoney()) {
                $this->flagDiscrepancy($event, $parcel, $payload);
            }

            $this->changeStatusFor($order, $target, $payload, $actor);

            if ($code->collectsMoney()) {
                $this->settleMoney($order, $parcel, $actor);
            }

            // The one moment trustworthy enough to clear an older conflict: the parcel arrived
            // under the code we expected, carrying what we asked for.
            if ($code->collectsMoney() && ! $this->codeDiffers($parcel, $payload) && $parcel->hasOpenConflict()) {
                $parcel->forceFill(['conflict_resolved_at' => now()])->save();
            }

            if ($code->closesTheParcel()) {
                $parcel->forceFill(['closed_at' => now()])->save();
            }
        }, attempts: 3);
    }

    /**
     * The status move itself, with the courier's number and the carrier's own reason carried on
     * to the timeline.
     */
    private function changeStatusFor(
        Order $order,
        OrderStatus $target,
        NawrisWebhookPayload $payload,
        User $actor,
    ): void {
        // **«إلغاء تام» demands a reason and the webhook has none of its own.**
        // `ChangeOrderStatus` throws `TransitionRequiresReason` on a null, so every code 12 would
        // fail without this. Their `return_reason` when they sent one, a synthetic line naming
        // them when they did not.
        $reason = $target->requiresReason()
            ? ($payload->returnReason ?? 'أُغلقت من قبل شركة نورس (رمز '.$payload->statusCode.')')
            : $payload->returnReason ?? $payload->delayReason;

        ($this->changeStatus)($order, $target, $reason, $actor);

        if ($payload->captainPhone !== null) {
            // Last courier wins — the number you ring is the one holding it now.
            $order->forceFill(['courier_phone' => $payload->captainPhone])->save();
        }
    }

    /**
     * The money, on code 7 only.
     *
     * **Two entries, one transaction, one idempotence flag.** The COD Nawris remits is a cash
     * payment; the delivery fee we took off the COD before dispatch was paid by the customer to
     * the courier, and closes the order without ever reaching our drawer — see
     * `OrderPaymentType::CarrierSettled`. Without both, a Nawris order can never reach «تم
     * التسوية» except through a write-off, which would post a loss for every delivery.
     *
     * `carrier_collection_recorded_at` is the last line of defence against a duplicate delivery
     * notice writing either entry twice, and it lives on the *order* so it survives the parcel
     * being deleted, re-created or re-dispatched.
     */
    private function settleMoney(Order $order, NawrisParcel $parcel, User $actor): void
    {
        $order->refresh();

        if ($order->carrier_collection_recorded_at !== null) {
            return;
        }

        $remitted = (string) $parcel->amount_to_collect;
        $fee = (string) $parcel->delivery_price_deducted;

        // Clamped to what is actually outstanding: `RecordOrderPayment` refuses anything over the
        // remainder, and a webhook must never blow up on an order somebody part-paid at the
        // counter after dispatch. Any excess surfaces as a discrepancy, not as a crash.
        $remitted = $this->clamp($remitted, $order->remainingAmount());

        if (bccomp($remitted, '0', Money::SCALE) > 0) {
            ($this->recordPayment)($order, OrderPaymentData::fromArray([
                'amount' => $remitted,
                'method' => PaymentMethod::Cash->value,
                'notes' => "محصّلة من نورس — الطرد {$parcel->code}",
            ]), $actor);
        }

        $order->refresh();
        $fee = $this->clamp($fee, $order->remainingAmount());

        if (bccomp($fee, '0', Money::SCALE) > 0) {
            ($this->recordCarrierSettlement)(
                $order,
                $fee,
                "أجرة التوصيل محصّلة لدى نورس — الطرد {$parcel->code}",
                $actor,
            );
        }

        $order->forceFill(['carrier_collection_recorded_at' => now()])->save();
    }

    /**
     * What actually came back, and whether it is short.
     *
     * **The comparison is a floor, not an equality.** The courier adds their own fee on top of our
     * COD at the door, so `order_price` is always *at least* what we asked for — comparing for
     * equality would raise a false discrepancy on every single order, which is the contract's own
     * warning. Anything above is their fee; anything below is money missing, and only that is
     * worth an alert.
     *
     * **Alert, never block.** An earlier version of the system this came from blocked, and froze
     * orders indefinitely at "with the courier" while cash that had genuinely been collected sat
     * outside the books.
     */
    private function flagDiscrepancy(NawrisWebhookEvent $event, NawrisParcel $parcel, NawrisWebhookPayload $payload): void
    {
        if ($payload->collectedAmount === null) {
            return;
        }

        $parcel->forceFill(['collected_amount' => $payload->collectedAmount])->save();

        $expected = (string) $parcel->amount_to_collect;

        // Two decimal places with a small epsilon, so a rounding difference is not an incident.
        if (bccomp(bcadd($payload->collectedAmount, '0.01', Money::SCALE), $expected, Money::SCALE) < 0) {
            $event->forceFill([
                'error' => "نقص في التحصيل: المطلوب {$expected} والواصل {$payload->collectedAmount}",
            ])->save();
        }
    }

    /**
     * **A different code alone proves nothing** — a legitimate re-send arrives under a new one —
     * so the decision is compound: a different code *and* an amount that does not match blocks
     * the transition entirely. A different code with a matching amount is let through and merely
     * flagged, because freezing an order on unproven suspicion is worse than a late review.
     */
    private function conflictBlocks(NawrisParcel $parcel, NawrisWebhookPayload $payload): bool
    {
        if (! $this->codeDiffers($parcel, $payload)) {
            return false;
        }

        if ($payload->collectedAmount === null) {
            // Cannot be checked — let it through and flag it.
            return false;
        }

        $expected = (string) $parcel->amount_to_collect;

        return bccomp(bcadd($payload->collectedAmount, '0.01', Money::SCALE), $expected, Money::SCALE) < 0;
    }

    private function codeDiffers(NawrisParcel $parcel, NawrisWebhookPayload $payload): bool
    {
        return $payload->code !== null
            && $parcel->code !== null
            && $payload->code !== $parcel->code;
    }

    /** Their label, verbatim, and their integer — which is what the mapping is written against. */
    private function recordCarrierState(
        NawrisParcel $parcel,
        NawrisWebhookPayload $payload,
        ?NawrisStatusCode $code,
    ): void {
        $parcel->forceFill([
            'remote_status_code' => $payload->statusCode,
            'remote_status_text' => $payload->statusText,
        ])->save();

        unset($code);
    }

    private function clamp(string $amount, string $ceiling): string
    {
        if (bccomp($ceiling, '0', Money::SCALE) <= 0) {
            return '0.00';
        }

        return bccomp($amount, $ceiling, Money::SCALE) > 0 ? Money::round($ceiling) : Money::round($amount);
    }
}
