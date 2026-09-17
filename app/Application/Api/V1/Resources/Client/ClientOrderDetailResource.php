<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources\Client;

use App\Domain\Order\Enums\CustomerOrderStage;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderStatusTransition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One order, opened.
 *
 * **The timeline is the stages this order actually reached, not its transitions.** The workshop
 * records every move — «جاهزة للطباعة» at 14:05, «قيد الطباعة» at 14:40 — and five of those
 * collapse into «قيد التجهيز». Sending the raw list would put the workshop's vocabulary on the
 * screen through the back door, and would draw five ticks for one thing happening. So the
 * transitions are folded into stages, each keeping the moment it was *first* reached.
 *
 * The money is the numbers a customer can act on: what the order came to, what they have paid,
 * and what is left. Not the cost, not the margin, not what the courier charged us.
 *
 * @mixin Order
 */
class ClientOrderDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stage = CustomerOrderStage::forStatus($this->status);

        // **Asked once, at the top, because six fields below depend on the answer.** True only
        // while the order sits in «بانتظار المراجعة» carrying a line priced «حسب الطلب» that
        // nobody has quoted yet — `ChangeOrderStatus` refuses to let it leave that status in
        // that condition.
        $awaitingQuote = $this->hasUnpricedLines();

        return [
            'id' => $this->id,
            'code' => $this->code,

            'stage' => $stage->value,
            'stage_label' => $stage->label(),
            'is_open' => $stage->isOpen(),
            'stage_hint' => $stage->hint(),

            // **Why we would not take it — and `cancellation_reason` is deliberately not here.**
            // The two are different sentences with different audiences: one is written for the
            // accountant about an order the shop took and wrote off, and it stays on the staff
            // side. This one is the shop's answer to the person who placed the order, written
            // to be read by them. Null on every order that was not refused.
            'rejection_reason' => $this->rejection_reason,

            // Where it is going, as the order recorded it. The city and region names are the
            // snapshot the order carries rather than a live lookup — a renamed district must not
            // rewrite where an old order said it was headed.
            'city_name' => $this->city_name,
            'region_name' => $this->region_name,
            'address_details' => $this->address_details,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'fulfilment_type' => $this->fulfilment_type->value,
            'fulfilment_type_label' => $this->fulfilment_type->label(),

            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'product_name' => $item->product_name,
                    'variant_label' => $item->variant_label,
                    'quantity' => (string) $item->quantity,
                    // **Null, never '0.00'.** A line the shop has not quoted yet has no price,
                    // and a zero here would read as «مجاناً» on the customer's screen.
                    'unit_price' => $item->unit_price === null ? null : (string) $item->unit_price,
                    'line_total' => $item->line_total === null ? null : (string) $item->line_total,
                ])->all(),
            ),

            // Every number here is one the customer is owed an answer about. `total_cogs`,
            // `unit_cost` and the margin columns live on the staff resource and stay there.
            //
            // **And every figure an unpriced order would understate is sent as null instead.**
            //
            // While a line is waiting to be quoted the stored `items_total` and `grand_total`
            // are the sum of the lines that *do* have a price, which is a smaller number than
            // the order will come to — see {@see \App\Domain\Order\Actions\RecalculateOrderTotals}.
            // Sending it would put a figure on the customer's screen that is not what they will
            // be asked to pay, and they would have every right to hold us to it. Null says «لا
            // سعر بعد», which is the truth, and the app draws «يُحدَّد بعد المراجعة».
            //
            // `delivery_price` survives: the courier's fee is known from the destination and is
            // not part of these sums at all.
            'items_total' => $awaitingQuote ? null : (string) $this->items_total,
            'delivery_price' => (string) $this->delivery_price,
            'design_fee' => $awaitingQuote ? null : (string) $this->design_fee,
            'discount' => $awaitingQuote ? null : (string) $this->discount,
            'total' => $awaitingQuote ? null : (string) $this->grand_total,
            'paid_amount' => $awaitingQuote ? null : (string) $this->paid_amount,
            'balance' => $awaitingQuote ? null : $this->remainingAmount(),

            // The decided answer, so the app draws «يُحدَّد بعد المراجعة» without having to work
            // out from a pile of nulls what they mean between them.
            'is_awaiting_quote' => $awaitingQuote,

            'timeline' => $this->whenLoaded('transitions', fn () => $this->timeline()),

            'placed_at' => $this->placed_at?->toIso8601String(),
        ];
    }

    /**
     * The stages this order reached, each at the moment it was first reached.
     *
     * **Folded, not filtered.** Five workshop statuses read as «قيد التجهيز», so an order that
     * walked all five shows one entry rather than five identical ones — and it carries the
     * *first* of those moments, because that is when the order started being got ready. Keying
     * by stage value and skipping a repeat does both.
     *
     * @return list<array<string, mixed>>
     */
    protected function timeline(): array
    {
        $seen = [];

        foreach ($this->transitions as $transition) {
            /** @var OrderStatusTransition $transition */
            $stage = CustomerOrderStage::forStatus($transition->to_status);

            if (isset($seen[$stage->value])) {
                continue;
            }

            $seen[$stage->value] = [
                'stage' => $stage->value,
                'stage_label' => $stage->label(),
                'reached_at' => $transition->created_at?->toIso8601String(),
            ];
        }

        return array_values($seen);
    }
}
