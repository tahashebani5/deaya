<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

use App\Domain\Order\Models\Order;
use App\Domain\Order\Support\Money;
use Illuminate\Support\Str;

/**
 * The twenty-four fields Nawris expects, built in one place.
 *
 * **This class and `NawrisClient` are the only two files that know Nawris's own shapes**, which
 * is what keeps the cost of the contract being wrong down to two files.
 *
 * **{@see amountToCollect()} is the single COD function, and it is the most load-bearing method
 * in the integration.** Dispatch and every edit call it, which is the contract's first field
 * rule: *"if create and edit disagree, an edit silently re-bills money the customer already
 * paid."* There is no second place that computes this.
 *
 * Nulls and empty values are stripped before the request goes out. That matters far more on an
 * edit than on a create: an omitted field leaves Nawris's copy of it untouched rather than
 * clearing it, so the payload is always built whole.
 */
final class BuildNawrisPayload
{
    /**
     * @param  array<string, mixed>  $config  `services.nawris`
     */
    public function __construct(private readonly array $config) {}

    /**
     * The full payload for one order.
     *
     * **The destination is passed in, never re-derived**, because an edit has to replay exactly
     * what creation sent: re-reading it from the order at edit time drifts, and an edit carrying
     * a different area *moves the parcel*.
     *
     * `$code` present makes this an edit — it is the only difference between the two payloads.
     *
     * @return array<string, mixed>
     */
    public function forOrder(
        Order $order,
        string $government,
        ?string $area = null,
        ?string $code = null,
    ): array {
        $defaults = (array) ($this->config['defaults'] ?? []);

        $payload = [
            // **Not a person's name.** It is what gets read off the label at handover, and the
            // order code is what a person here can act on when a courier rings about it.
            'receiver' => (string) $order->code,

            'phone1' => $this->phone($order),

            'government' => $government,
            'area' => $area,

            'order_summary' => 'طلبية أكياس',

            'amount_to_be_collected' => (float) $this->amountToCollect($order),

            'remote_order_id' => $this->reference($order),

            'return_amount' => 0.0,

            // **Who pays the courier's own fee. Always the customer, at the door.** Delivery is
            // no longer part of what we bill — see {@see amountToCollect()} — so there is no
            // case left in which the fee falls on us, and `0` is also their default.
            'shipment_on_sender' => 0,

            'can_open' => (int) ($defaults['can_open'] ?? 0),
            'is_measurable' => (int) ($defaults['is_measurable'] ?? 0),
            'is_order' => (int) ($defaults['is_order'] ?? 0),
            'pieces_count' => (int) ($defaults['pieces_count'] ?? 1),
            'extra_cost_payer' => (int) ($defaults['extra_cost_payer'] ?? 1),
            'is_office_given' => (int) ($defaults['is_office_given'] ?? 0),
            'is_fragile' => (int) ($defaults['is_fragile'] ?? 0),
            'accept_20_plus_5_dinar' => (int) ($defaults['accept_20_plus_5_dinar'] ?? 0),
        ];

        if ($code !== null) {
            $payload['code'] = $code;
        }

        return $this->strip($payload);
    }

    /**
     * What we ask Nawris to collect and remit: what the customer still owes us.
     *
     * ```
     * grand_total − paid_amount − written_off_amount − carrier_settled_amount   (remainingAmount)
     * ```
     *
     * **Every deposit and installment is already off it**, through `remainingAmount()` — that is
     * the contract's field rule #1, and the reason a payment recorded after dispatch triggers an
     * edit rather than being left to drift.
     *
     * **Nothing is taken off for delivery any more, because nothing was put on.** The fee left
     * `grand_total` — see {@see \App\Domain\Order\Actions\RecalculateOrderTotals} — so the
     * courier charging it at the door on their own account bills it exactly once, which is what
     * the old subtraction was arranging by hand. Subtracting it from a total that no longer
     * contains it would collect less than the customer owes. NAWRIS-INTEGRATION.md §5.2.
     *
     * Clamped at zero: a negative figure confuses the carrier's own tracking, and «اجمع مبلغاً
     * سالباً» is not an instruction — an overpaid order needs a refund, not a negative COD.
     */
    public function amountToCollect(Order $order): string
    {
        $remaining = $order->remainingAmount();

        return bccomp($remaining, '0', Money::SCALE) > 0 ? Money::round($remaining) : '0.00';
    }

    /**
     * Our correlation id, minted here and echoed back by every webhook.
     *
     * Unique per *parcel* rather than per order, so a re-send gets a fresh one while the order
     * keeps its identity.
     *
     * **Stability across edits comes from storage, not from this method.** An edit replays the
     * reference off the parcel row; it never asks for a new one, because changing it detaches the
     * shipment from the record.
     *
     * **The random suffix is not decoration.** The contract's shape is `{order}_{unixTime}`, and
     * that collides whenever the same order is dispatched twice inside one second — a retry loop
     * being the obvious way — landing a unique-index violation at exactly the moment something is
     * already going wrong. The suffix is four characters of our own opaque id, which Nawris does
     * not interpret.
     */
    public function reference(Order $order): string
    {
        return $order->code.'_'.now()->getTimestamp().'_'.Str::lower(Str::random(4));
    }

    /**
     * The recipient's number in the form their validation accepts.
     *
     * Falls back to the configured placeholder when we simply do not have one, so a parcel is
     * never refused over a field the customer never gave us. The order's own recipient wins over
     * the customer's, because it is the person actually receiving the parcel.
     */
    private function phone(Order $order): string
    {
        $raw = $order->recipient_phone ?? $order->customer?->phone;

        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        $national = match (true) {
            str_starts_with($digits, '00218') => substr($digits, 5),
            str_starts_with($digits, '218') => substr($digits, 3),
            str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };

        // A Libyan mobile is nine digits once the country code and the trunk zero are off. Not a
        // validation rule — the number is the customer's, and refusing to dispatch over its shape
        // would be this integration deciding something the order screen already decided.
        return strlen($national) === 9
            ? '+218'.$national
            : (string) ($this->config['fallback_phone'] ?? '+218910000000');
    }

    /**
     * Nulls and empty strings never go out.
     *
     * On a create it makes no difference. On an edit it is the whole point: a field sent as null
     * is *ignored* by Nawris rather than cleared, so sending one would be a silent no-op that
     * looks like an instruction.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function strip(array $payload): array
    {
        return array_filter(
            $payload,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }
}
