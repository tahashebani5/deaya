<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Order\DTOs\TransitionField;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\StockEffectPreview;
use App\Domain\Order\Support\TransitionFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Whether this payload is a single order rather than a row in a list.
     *
     * Set by the endpoints that return one — see {@see withStockEffect()} — and false everywhere
     * else, which is what keeps `stock_effect` off a page of twenty.
     */
    private bool $previewsStockEffect = false;

    /**
     * Ask this order to say what a delete — or, if it is already archived, a restore — would do
     * to the warehouse.
     *
     * **Opted into by the endpoint rather than decided here, and that is deliberate.** The
     * warning is built from the lines and the movement ledger behind each of them, so a resource
     * that computed it unasked would read the ledger once per row for a sentence no card shows.
     * A list cannot call this: `collection()` never touches the individual resources.
     *
     * The same shape {@see ActivityLogResource::withReferenceNames()} uses, for the same reason —
     * something the caller knows and the resource cannot.
     */
    public function withStockEffect(): self
    {
        $this->previewsStockEffect = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // What a person says on the phone. Plain digits, unlike the customer's C7 and the
            // product's P7 — an order number is said on its own, so a prefix would be a
            // syllable to spell out for no information.
            'code' => $this->code,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // **«مستعجلة» — a decision, not a status.** It rides beside the status rather than
            // inside it because it is true of an order at *any* step: a rush job is rushed while
            // it is being designed, printed and delivered, and folding it into the state machine
            // would double every case in it. No label travels with it: unlike a status, it is one
            // word the app already knows and has nothing to translate.
            'is_urgent' => (bool) $this->is_urgent,

            // ── «هل أُبلِغ الزبون أنّ طلبه جاهز؟» ────────────────────────────────────────────
            // The one fact about an order that happens outside this system — a message on
            // WhatsApp, a telephone call — so it is here only because a person recorded it. Four
            // keys and not one, because the screen asks four different things of them.

            // **Whether the question applies at all**, answered by the server rather than left to
            // a client comparing statuses. `Order::readyMessageApplies()` reads `ready_at`, which
            // is stamped once and never cleared: the box is drawn on «جاهزة» and stays drawn
            // through delivery and after it, which is exactly when «هل أُبلِغ أصلاً؟» is asked.
            // A list of statuses written in Dart would be a second copy of the map.
            'ready_message_applies' => $this->readyMessageApplies(),

            'is_ready_message_sent' => $this->ready_message_sent_at !== null,
            'ready_message_sent_at' => $this->ready_message_sent_at?->toIso8601String(),

            // **Who said so**, because the whole purpose of the mark is confirming that the
            // person responsible did the work. Only where the relation is in hand — the order
            // screen loads it, a page of twenty does not and no card shows a name — so this is
            // absent on a list row rather than costing a query per row.
            'ready_message_sent_by' => $this->whenLoaded(
                'readyMessenger',
                fn () => $this->readyMessenger === null ? null : [
                    'id' => $this->readyMessenger->id,
                    'name' => $this->readyMessenger->name,
                ],
            ),

            // Who is making it, for an order a vendor executes. The name travels with the id
            // because it is what this order said at the time — a vendor renamed since keeps its
            // new name everywhere except here. Null on every order that is made in-house.
            'vendor_id' => $this->vendor_id,
            'vendor_name' => $this->vendor_name,

            // **Which road this order walks**, so a client can *explain* a five-step bar rather
            // than leaving one that looks truncated. It is not a second copy of the rules —
            // `available_transitions` and `progress` below already have the answers baked in —
            // it is the reason for them, which is the one thing a screen cannot derive from
            // either.
            'production_flow' => $this->production_flow->value,
            'production_flow_label' => $this->production_flow->label(),
            // Two questions, and «تم الاستلام» answers them differently: nothing about the order
            // may be edited any more (`is_closed`), but it still owes its settlement, so it is
            // not finished (`is_final`).
            'is_final' => $this->status->isFinal(),
            'is_closed' => $this->status->isClosed(),

            // **Everything this payload offers to *do* to this order**, and nothing at all of
            // it once the order is archived — see {@see offers()} for why the gate is one gate.
            ...$this->offers($request),

            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'customer_shop_id' => $this->customer_shop_id,
            // The snapshot, for the same reason city_name is one.
            'customer_shop_name' => $this->customer_shop_name,
            'shop' => new CustomerShopResource($this->whenLoaded('shop')),

            'city_id' => $this->city_id,
            'region_id' => $this->region_id,
            // The snapshot, not the live map: this is what the order said on the day, and it
            // survives the city being renamed or removed.
            'city_name' => $this->city_name,
            'region_name' => $this->region_name,

            'fulfilment_type' => $this->fulfilment_type->value,
            'fulfilment_type_label' => $this->fulfilment_type->label(),
            'is_office_pickup' => $this->fulfilment_type->isOfficePickup(),

            'design_source' => $this->design_source->value,
            'design_source_label' => $this->design_source->label(),

            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'address_details' => $this->address_details,
            'notes' => $this->notes,

            // Strings: money that is summed must reach the client exactly as it was stored.
            'items_total' => (string) $this->items_total,
            'design_fee' => (string) $this->design_fee,
            'delivery_price' => (string) $this->delivery_price,
            'discount' => (string) $this->discount,
            // Beside the discount and never folded into it: an order's total is read as «هذا ما
            // أُضيف وهذا ما خُصم», and one net figure explains neither. The reason travels with
            // its label for the same reason `design_source` does — a client that translated the
            // code itself would be keeping a second copy of a list this API owns.
            'additional_cost' => (string) $this->additional_cost,
            'additional_cost_reason' => $this->additional_cost_reason?->value,
            'additional_cost_reason_label' => $this->additional_cost_reason?->label(),
            'additional_cost_note' => $this->additional_cost_note,
            'grand_total' => (string) $this->grand_total,

            // The cost side, null until the order has reached printing — see the order_items
            // migration and DeductOrderStock/ApplyManufacturingRates. `gross_profit` is computed
            // here from the two cached figures beside it, never stored itself.
            'total_cogs' => $this->total_cogs === null ? null : (string) $this->total_cogs,
            'gross_profit' => $this->grossProfit(),

            // **The three numbers a screen puts side by side**, all three computed here. A
            // client subtracting `grand_total - paid_amount` itself would be a second answer to
            // one question, and its answer is the one made of doubles — and since a debt can
            // also be closed by writing it off, that subtraction is no longer even the right
            // one. `remainingAmount()` is.
            //
            // `paid_amount` is the ledger's running total — see the `order_payments` migration
            // for why the entries are the truth and this is their sum. `remaining_amount` goes
            // negative on an overpaid order rather than flooring, so a screen can say «زائد ٥٠»
            // and somebody can refund it.
            'paid_amount' => (string) $this->paid_amount,
            // The fourth number, and usually zero: what was closed without being collected. It
            // stays out of `paid_amount` so that column never stops meaning cash — see
            // OrderPaymentType::WriteOff.
            'written_off_amount' => (string) $this->written_off_amount,
            // The fifth, and zero on everything that did not go out through a carrier who
            // collected our delivery fee at the door. Carried beside the other two because
            // `remaining_amount` is now derived from all three: a screen showing 100 paid against
            // a 120 order with nothing outstanding needs the twenty to be visible somewhere, or
            // the arithmetic on screen reads as a bug. See OrderPaymentType::CarrierSettled.
            'carrier_settled_amount' => (string) $this->carrier_settled_amount,
            'remaining_amount' => $this->remainingAmount(),
            'payment_status' => $this->paymentStatus()->value,
            'payment_status_label' => $this->paymentStatus()->label(),

            // **An order that finished without its money accounted for.** Settling an order
            // writes no ledger entry — nothing records a payment except the person who took it —
            // so this is how that gap is surfaced rather than papered over with an entry nobody
            // made. See Order::hasUnrecordedMoney().
            'has_unrecorded_money' => $this->hasUnrecordedMoney(),

            // ── العربون ──────────────────────────────────────────────────────────────────────
            // **Three facts, deliberately not merged.** What was asked for, what the counter
            // claimed, and whether a second person has checked. Published even on orders that
            // never asked for a deposit — null and false — because a client distinguishing
            // "absent" from "no" is a client written against one server's mood.

            // The arrangement. **Not money that has moved**, and nothing sums it: the real
            // deposit is an ordinary entry in `payments`, already counted in `paid_amount` above.
            'deposit_expected_amount' => $this->deposit_expected_amount === null
                ? null
                : (string) $this->deposit_expected_amount,
            'deposit_expected_method' => $this->deposit_expected_method?->value,
            'deposit_expected_method_label' => $this->deposit_expected_method?->label(),

            // The claim: when the order was said to have been paid. Cleared if it is walked back.
            'deposit_paid_at' => $this->deposit_paid_at?->toIso8601String(),

            // The confirmation, and who made it.
            'is_deposit_received' => (bool) $this->is_deposit_received,
            'deposit_confirmed_at' => $this->deposit_confirmed_at?->toIso8601String(),

            // **Whether the box may be tapped, answered by the server.** It folds two things a
            // client cannot see together: the `orders.deposit.confirm` grant, and the rule that
            // whoever moved the order to «عربون مدفوع» is not the one who confirms it. Without
            // this the app would have to keep its own copy of a rule it cannot evaluate — it does
            // not know who made the claim — and would grey the box wrongly or not at all.
            'can_confirm_deposit' => $this->depositIsConfirmableBy($request->user()),

            // **A claim nobody has checked yet — the accountant's queue, and not a problem.** The
            // order goes on being printed and delivered throughout; this exists so the screen can
            // show the row and the report can count it.
            'awaits_deposit_confirmation' => $this->awaitsDepositConfirmation(),

            // Who said the عربون was paid, and who confirmed it — two different people by rule.
            // Loaded only where the relations are in hand, like `ready_message_sent_by` above:
            // the order screen loads them, a page of twenty does not.
            'deposit_claimed_by' => $this->whenLoaded(
                'depositClaimer',
                fn () => $this->depositClaimer === null ? null : [
                    'id' => $this->depositClaimer->id,
                    'name' => $this->depositClaimer->name,
                ],
            ),
            'deposit_confirmed_by' => $this->whenLoaded(
                'depositConfirmer',
                fn () => $this->depositConfirmer === null ? null : [
                    'id' => $this->depositConfirmer->id,
                    'name' => $this->depositConfirmer->name,
                ],
            ),

            // Null on every settlement that went to plan: the order was settled at its own
            // total. A value here is a discrepancy, deliberately.
            'collected_amount' => $this->collected_amount === null ? null : (string) $this->collected_amount,

            // The snapshot beside the key, like the city's: what the order said carried it,
            // which survives the company being renamed or removed from the list.
            'shipping_company_id' => $this->shipping_company_id,
            'shipping_company' => $this->shipping_company,
            'courier_phone' => $this->courier_phone,
            'tracking_number' => $this->tracking_number,

            // **The carrier's own code for the parcel this order went out in.** Not
            // `tracking_number`, which is a box somebody types into: this one is what Nawris
            // called the parcel, and it is the number said out loud when a customer rings about
            // a delivery. Attached by the controller rather than read here, because `Order` may
            // not know a carrier exists — see CarrierService::parcelCodesFor().
            //
            // Absent, not null, wherever it was not attached: a client that got no key knows the
            // question was not asked, where a null would say it was asked and came back empty.
            'nawris_parcel' => $this->when(
                isset($this->nawris_parcel),
                fn (): array => $this->nawris_parcel,
            ),

            // Where this order's stock came out of, and when — both null until the order first
            // enters `printing`. See DeductOrderStock.
            'fulfillment_warehouse_id' => $this->fulfillment_warehouse_id,
            'stock_deducted_at' => $this->stock_deducted_at?->toIso8601String(),

            // **What the parcel weighs, summed from the lines** — the order has no weight column
            // any more, and the one it had was a number nobody derived anything from. See
            // Order::totalWeight() for what null means, which is «no weight to state» rather than
            // zero, and why a shelf counted in pieces contributes nothing to it.
            //
            // Only where the lines are in the payload, which is both endpoints: the list eager-
            // loads them for the moves it offers per row, and the card states the weight beside
            // the money. What the key must not do is fetch them — an order weighed by pulling
            // four lines apiece for a page of twenty is a query per row, so OrderListQuery loads
            // the shelf behind each line too and this costs nothing wherever it is read.
            'total_weight' => $this->when(
                $this->resource->relationLoaded('items'),
                fn () => $this->totalWeight(),
            ),

            // **Whether the customer left part of this order behind** — the chip in the orders
            // list, and the reason the order screen draws a line about it at all.
            //
            // Derived rather than cached, for the reason `Order::grossProfit()` gives about
            // itself: the inputs are already here, and a column to keep in step could only ever
            // come to disagree with them. It costs no query for exactly the same reason
            // `total_weight` above costs none — `OrderListQuery` already eager-loads the lines
            // for the moves it offers per row — which is what made the chip affordable and
            // overturned the recommendation against it. See PARTIAL-DELIVERY-DESIGN.md §3,
            // Decision 7.
            //
            // Guarded on the relation like its neighbour, and for the same reason: a key that
            // *fetches* the lines would be a query per row on a page of twenty.
            'is_partially_delivered' => $this->when(
                $this->resource->relationLoaded('items'),
                fn (): bool => $this->items->contains(
                    fn (OrderItem $item): bool => $item->undelivered_quantity !== null
                        && bccomp((string) $item->undelivered_quantity, '0', 3) > 0,
                ),
            ),

            'placed_at' => $this->placed_at?->toIso8601String(),
            // When the warehouse finished and handed the order to the press — null for every
            // order taken before that step existed, which is the honest answer for them.
            'ready_to_print_at' => $this->ready_to_print_at?->toIso8601String(),
            'design_started_at' => $this->design_started_at?->toIso8601String(),
            'printing_started_at' => $this->printing_started_at?->toIso8601String(),
            'manufacturing_started_at' => $this->manufacturing_started_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'returned_at' => $this->returned_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,

            'items_count' => $this->whenCounted('items'),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'designs' => OrderDesignResource::collection($this->whenLoaded('designs')),
            'transitions' => OrderStatusTransitionResource::collection($this->whenLoaded('transitions')),

            // **The ledger itself is deliberately not here.** It has its own endpoint behind its
            // own permission — `GET /orders/{order}/payments`, `orders.payments.view` — and
            // including the entries in this payload would hand them to everybody holding
            // `orders.view`, which is the printer.
            //
            // The four summary fields above stay, and the line between them is meant: what an
            // order costs and what is outstanding on it are properties of the order, at the same
            // sensitivity as `grand_total`, which this payload has always carried. Who took the
            // money, by what method, against which receipt, and which entries were cancelled —
            // that is the ledger, and it is a different question with a different grant.

            'created_by' => $this->whenLoaded('creator', fn () => $this->creator === null ? null : [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // **When this order was deleted, and null on every order that was not.** On every row
            // of both lists rather than on the archive's alone: the app patches a row from
            // whatever an endpoint hands back, and this is the one field that decides which of
            // the two lists that row still belongs to. A key present only in the archive would
            // leave the live list unable to drop an order it has just deleted without asking the
            // server again for a page it already has.
            'deleted_at' => $this->deleted_at?->toIso8601String(),

            // **What the warehouse is about to do, said before the button is tapped**: what a
            // delete would put back on the shelf, or — once the order is archived — what a
            // restore would take off it again. On the single-order endpoints only; see
            // {@see withStockEffect()}.
            //
            // **Composed by the domain rather than here**, and that is the whole reason it can be
            // trusted: {@see StockEffectPreview} reads the same accessor
            // {@see \App\Domain\Order\Actions\DeductOrderStock} deducts against and the same
            // ledger the delete reverses, so the sentence on the confirmation cannot promise a
            // movement the action will not make. A copy of that arithmetic written in this
            // resource — or in Dart — would be right the day it was written and wrong the first
            // time either action changed.
            'stock_effect' => $this->when(
                $this->previewsStockEffect,
                fn (): array => StockEffectPreview::for($this->resource),
            ),
        ];
    }

    /**
     * Every key that offers to *change* this order — and, once it is archived, nothing at all.
     *
     * **One gate for the whole group rather than a gate per key, because the per-key version has
     * already failed.** The first cut wrote the archive check into `available_transitions` and
     * `progress` and left the four keys standing beside them reading from the status alone: a
     * deleted order went on publishing `reinstate_to` and `items_are_editable: true`, so the app
     * drew «تراجع عن الإلغاء» and opened the line editor over routes that answer 404 — every
     * write bound to `{order}` resolves live orders only, deliberately. The rejected alternative
     * is the obvious one, `$this->when(! $archived, …)` repeated on each key, and it is exactly
     * what drifted: it asks whoever adds the *next* action key to remember a rule written
     * nowhere near them. Here the rule is the method the key is being added to.
     *
     * **Absent, never empty or false.** «هذه الطلبية لا تملك حركة» and «هذه الطلبية ليست مما
     * يتحرك» are two different sentences and only the missing key says the second — §٦ of
     * Docs/orders/ORDER-DELETE-AND-ARCHIVE.md. Nothing is lost on the way: the app already reads
     * a missing key as «لا», because `Order.itemsAreEditable` and its two neighbours default to
     * `false` and `reinstateTo` defaults to null.
     *
     * `stock_effect` stays out of the group on purpose: a restore is the one move an archived
     * order *does* have, and describing it is why the archive screen opens at all.
     *
     * @return array<string, mixed>
     */
    private function offers(Request $request): array
    {
        if ($this->resource->trashed()) {
            return [];
        }

        // Read once: two keys below describe the same answer, and finding it means walking the
        // order's timeline. Null on every order that is not a cancellation this user may undo,
        // which is nearly all of them.
        $reinstateTo = $this->reinstatableToFor($request);

        return [
            // **The whole point of gating the app on the server.** The moves this order may
            // make, already narrowed to the ones *this* user may make, so the app draws exactly
            // the buttons that will work instead of keeping its own copy of the rules and
            // offering one the server will refuse.
            //
            // `fields` carries that same idea one step further: what a move *asks for* travels
            // with the move, so the app renders a form it was handed rather than one it wrote,
            // and a new field on a path is a change here alone. See {@see TransitionFields}.
            //
            // **The key that named the gate above.** The card is shared between the two lists,
            // and it offers a status move on any row that carries this key — so an order
            // somebody deleted would sit in the archive with «نقل إلى قيد الطباعة» under it.
            'available_transitions' => array_map(
                fn (OrderStatus $target) => [
                    'status' => $target->value,
                    'label' => $target->label(),
                    'requires_reason' => $target->requiresReason(),
                    'fields' => array_map(
                        fn (TransitionField $field) => $field->toArray(),
                        // The signed-in user, because one field depends on them: money may only
                        // be taken by somebody trusted to record it, and the box is withheld
                        // from a driver rather than the move being withheld.
                        TransitionFields::for($this->resource, $target, $request->user()),
                    ),
                ],
                $this->availableTransitionsFor($request->user()),
            ),

            // **The way out of «إلغاء تام», and the only one there is.** An order written off by
            // mistake is put back exactly where it stood — the destination is read from the
            // timeline, never chosen — so what travels here is not a list of moves but the one
            // status the undo will land on, named so the app can say «ترجع إلى «استلام مكتب»»
            // on the button instead of asking somebody to tap and find out. Null whenever the
            // undo is not on offer: the order is not cancelled, this user lacks the grant, or
            // the timeline does not record what it was cancelled from. See
            // {@see \App\Domain\Order\Actions\ReinstateCancelledOrder}.
            'reinstate_to' => $reinstateTo?->value,
            'reinstate_to_label' => $reinstateTo?->label(),

            // The journey, in the domain's own order. Shipped with the order for the same
            // reason `available_transitions` is: which status follows which is knowledge this
            // API refuses to let a client keep a second copy of.
            //
            // **The one member of the group that is not an offer, and it earns its place.**
            // {@see Order::progress()} falls back to `furthestMainLineStep()` for every status
            // off the main line — «إلغاء تام», «نواقص», the three returns, «إعادة إرسال» — and
            // that reads `transitions` per order. Those are precisely the statuses an archive is
            // full of, and `transitions` is not eager-loaded by the list, so drawing a progress
            // bar nobody asked for would cost a query per row. Strict mode does not catch it:
            // it is a relation *query*, not a lazy relation read. The gate the archive needs for
            // that reason is the gate it needs for every key beside it, so it is the same gate.
            'progress' => $this->progress(),

            // Three different lines, and the app draws each section from the one that governs
            // it rather than keeping its own copy of where they fall. They are deliberately not
            // the same line: a quantity may be corrected while the press runs, the artwork may
            // not, and the address freezes later still.
            'items_are_editable' => $this->itemsAreEditable(),
            'designs_are_editable' => $this->designsAreEditable(),
            'destination_is_editable' => $this->destinationIsEditable(),
        ];
    }

    /**
     * The status «تراجع عن الإلغاء» would put this order back into, for this reader, or null.
     *
     * **Three conditions, and the app is told the answer rather than the conditions.** The order
     * must be standing in «إلغاء تام»; the reader must hold the cancellation's own grant, which
     * is what the route guards the write with; and the timeline must record what the order was
     * cancelled *from*, because that is the destination and there is no other source for it.
     * Answering with the status itself rather than with a boolean is what lets the button name
     * where the order is going.
     *
     * The timeline read is what the last condition costs, and it is why this is not offered to
     * a list: `loadForDisplay()` eager-loads `transitions` for the order screen, and
     * {@see Order::statusBeforeCancellation()} uses the loaded relation when it is there.
     */
    private function reinstatableToFor(Request $request): ?OrderStatus
    {
        if ($this->status !== OrderStatus::Cancelled) {
            return null;
        }

        if (! $request->user()?->can(PermissionName::CancelOrders->value)) {
            return null;
        }

        // **Only where the timeline is already in hand.** The order screen loads it — see
        // `OrderService::loadForDisplay()` — and a list does not, so asking here would be a
        // second query per cancelled row to fill a key no list draws. Null there, which is what
        // every other order in the list answers anyway: «no undo offered», the same thing a
        // client does with it either way.
        return $this->relationLoaded('transitions')
            ? $this->statusBeforeCancellation()
            : null;
    }
}
