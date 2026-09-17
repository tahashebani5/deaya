<?php

declare(strict_types=1);

namespace App\Domain\Order\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerShop;
use App\Domain\Delivery\Enums\FulfilmentType;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\Actions\AllocateOrderIdentifier;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Actions\DeleteOrder;
use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Actions\ReinstateCancelledOrder;
use App\Domain\Order\Actions\RestoreOrder;
use App\Domain\Order\Actions\ReverseOrderPayment;
use App\Domain\Order\Enums\AdditionalCostReason;
use App\Domain\Order\Enums\DesignSource;
use App\Domain\Order\Enums\OrderFlow;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Enums\PaymentStatus;
use App\Domain\Order\Exceptions\SettlementRequiresFullPayment;
use App\Domain\Order\Support\Money;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job of work: bags printed for a customer and got to them.
 *
 * `code` is deliberately absent from the fillable list — it is allocated by
 * {@see AllocateOrderIdentifier} and must never arrive in a request. So are the money columns
 * and every lifecycle timestamp: a total is derived from the lines by
 * {@see RecalculateOrderTotals} and a timestamp is stamped by the
 * transition that earned it. A client that could post `grand_total` could post any number it
 * liked.
 *
 * The address fields *are* fillable and are snapshots: see the migration for why nothing shown
 * on an old order is read back through its foreign keys.
 */
#[UseFactory(OrderFactory::class)]
#[Fillable([
    'customer_shop_id', 'customer_shop_name', 'vendor_id', 'vendor_name', 'city_id', 'region_id',
    'city_name', 'region_name', 'fulfilment_type',
    'design_source', 'recipient_name', 'recipient_phone', 'address_details', 'notes',
    'tracking_number', 'is_urgent',
])]
class Order extends Model implements HasAuditTrail
{
    /** @use HasFactory<OrderFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * Gives every order its number, on whatever path created it.
     *
     * On the model rather than in `CreateOrder`, for the reason products settled on: three
     * places create one — the action, the factory and any future importer — `code` is NOT NULL,
     * and an allocation living in only one of them is a crash waiting for the next caller.
     * There is exactly one correct code for any order, so nothing is taken from a caller by
     * settling it here.
     */
    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            if ($order->code === null) {
                $identifier = app(AllocateOrderIdentifier::class)();

                $order->id = $identifier->id;
                $order->code = $identifier->code;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            // «مستعجلة» — a decision somebody made about this order, not a measurement of how
            // long it has waited. Fillable, unlike every money column beside it, because there
            // is nothing a client could invent here that it is not entitled to say: this is the
            // clerk telling us what the customer asked for.
            'is_urgent' => 'boolean',
            'fulfilment_type' => FulfilmentType::class,
            // Which road this order walks, stamped at intake by `ResolveOrderFlow` and never
            // re-read afterwards — see that action for why it is a snapshot. Absent from the
            // fillable list for the reason `grand_total` is: a request that could post it could
            // skip the press on paper.
            'production_flow' => OrderFlow::class,
            'design_source' => DesignSource::class,
            // Strings, not floats: these are summed, and money that is summed must stay exact.
            'items_total' => 'decimal:2',
            'design_fee' => 'decimal:2',
            'delivery_price' => 'decimal:2',
            'discount' => 'decimal:2',
            // The charge that goes the other way — packaging, transport, a change to what was
            // agreed. Beside the discount rather than inside it, so «لماذا تغيّر الإجمالي؟» has
            // two readable halves rather than one net figure that answers nothing.
            'additional_cost' => 'decimal:2',
            // Cast, and that is the whole of what makes the change history say «تغليف خاص»
            // rather than `special_packaging` — see AuditValueLabels, which derives from here.
            'additional_cost_reason' => AdditionalCostReason::class,
            'grand_total' => 'decimal:2',
            // The cost-side twin of grand_total — written only by RecalculateOrderCogs, and
            // absent from the fillable list for the same reason grand_total is.
            'total_cogs' => 'decimal:2',
            // The ledger's running total. Written only by RecalculateOrderPayments and absent
            // from the fillable list for the same reason `grand_total` is: a request that could
            // set it could tell us it had been paid.
            'paid_amount' => 'decimal:2',
            // The other half of what closes a debt: what the business decided not to collect.
            // Same writer, same reason for staying out of the fillable list — and kept apart
            // from `paid_amount` so that column never stops meaning cash.
            'written_off_amount' => 'decimal:2',
            // The third thing that closes a debt: what the customer handed the courier instead of
            // us, because our delivery fee was taken off the COD before the parcel went out. Same
            // writer, same reason for staying out of the fillable list — and kept apart from both
            // of the others so `paid_amount` never stops meaning cash and `written_off_amount`
            // never stops meaning a loss. See OrderPaymentType::CarrierSettled.
            'carrier_settled_amount' => 'decimal:2',
            // The عربون: an expectation, a claim, and a confirmation — three facts deliberately
            // kept apart. **`deposit_expected_amount` is not money that has moved**, and nothing
            // sums it: the real deposit is an ordinary entry in `payments`, counted there like
            // every other. See ORDER-DEPOSIT-PLAN.md §٣٫٢.
            'deposit_expected_amount' => 'decimal:2',
            'deposit_expected_method' => PaymentMethod::class,
            'deposit_paid_at' => 'datetime',
            // Written by nothing but `ConfirmDepositReceipt`, read by nothing that gates: an
            // order whose deposit nobody has confirmed moves through the shop like any other.
            'is_deposit_received' => 'boolean',
            'deposit_confirmed_at' => 'datetime',
            // The idempotence flag behind both money entries a delivery webhook writes. On the
            // order rather than the parcel so it survives the parcel being deleted, re-created or
            // re-dispatched under a new code — see its migration.
            'carrier_collection_recorded_at' => 'datetime',
            // **Retired, and kept for the orders written before it was.** Nothing fills it any
            // more: settling used to ask «المبلغ المستلم» and write the answer here, a number no
            // total ever read — so an order could carry «المدفوع ٥٠٠» and «المستلم فعلياً ٤٥٠»
            // at once with nothing able to say which was true. That question the ledger now
            // answers exactly. See `TransitionFields::money()`.
            'collected_amount' => 'decimal:2',
            'placed_at' => 'datetime',
            'ready_to_print_at' => 'datetime',
            'design_started_at' => 'datetime',
            'printing_started_at' => 'datetime',
            'manufacturing_started_at' => 'datetime',
            // Stamped once by ChangeOrderStatus, on the first entry into `ready` — see
            // DeductOrderStock. Unlike ready_at, never overwritten by a later visit:
            // its whole job is to remember whether stock has already left the warehouse.
            'stock_deducted_at' => 'datetime',
            // What the *delete* did to the warehouse, so the restore can be an exact undo rather
            // than a guess — see {@see DeleteOrder}. Deliberately not the same fact as
            // `stock_deducted_at` above: that one remembers history and is never cleared, this
            // one describes what the archive is currently holding and is cleared by the restore.
            'delete_returned_stock_at' => 'datetime',
            'ready_at' => 'datetime',
            // When somebody said they had told the customer their bags are ready — the one fact
            // about an order that happens outside this system, so it is here only because a
            // person recorded it. Never fillable: it is stamped by MarkReadyMessageSent beside
            // the user who stamped it, and a request that could post it could put somebody
            // else's name against work they did not do.
            'ready_message_sent_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'settled_at' => 'datetime',
            'returned_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'request_rejected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<CustomerShop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(CustomerShop::class, 'customer_shop_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The warehouse this order's goods actually left, denormalised onto the order at the
     * handover — see the migration for why it is a column rather than a walk through the lines.
     *
     * **Soft-delete scoped, and that is what it is for.** `fulfillment_warehouse_id` is declared
     * `nullOnDelete`, but `DeleteWarehouse` soft deletes, so the foreign key never fires and the
     * column keeps naming a retired warehouse. `$order->fulfillmentWarehouse()->exists()` is
     * therefore the whole of «هل ما زال المخزن حيّاً؟» — the question {@see DeleteOrder} and
     * {@see RestoreOrder} both have to answer before they move a single bag, see
     * {@see FulfillmentWarehouseIsDeleted}.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function fulfillmentWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    /**
     * Who said the customer had been told their order is ready.
     *
     * Null on every order nobody has marked, and null again on one whose employee has since been
     * deleted — the stamp survives them, because the record is that a message went out.
     *
     * @return BelongsTo<User, $this>
     */
    public function readyMessenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ready_message_sent_by');
    }

    /**
     * Whether «رسالة الجاهزية» is a question this order has reached at all.
     *
     * **Read from `ready_at`, never from the status.** The stamp is written the first time an
     * order enters «جاهزة» and is never cleared, so this stays true while the parcel is out for
     * delivery and after the customer has it — which is exactly when «هل أُبلِغ أصلاً؟» is
     * asked. A list of statuses written here instead would be a second copy of the map, and the
     * copy that is forgotten the day a status is added after «جاهزة».
     */
    public function readyMessageApplies(): bool
    {
        return $this->ready_at !== null;
    }

    /**
     * Who moved this order into «عربون مدفوع» — the person who made the claim.
     *
     * Half of the four-eyes rule: the other half may not be the same user. Null on an order no
     * deposit was ever claimed on, and null again on one whose employee has since been deleted —
     * at which point nobody is barred, because there is nobody left to bar.
     *
     * @return BelongsTo<User, $this>
     */
    public function depositClaimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deposit_claimed_by');
    }

    /**
     * Who checked the account and confirmed the عربون is really there.
     *
     * @return BelongsTo<User, $this>
     */
    public function depositConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deposit_confirmed_by');
    }

    /**
     * The ledger entry the move into «عربون مدفوع» created, if it created one.
     *
     * Null when the clerk moved the order without taking money — the ordinary case — and null
     * when the deposit was recorded from the payments screen instead. **Only what this move
     * wrote is this move's to reverse**; see `ChangeOrderStatus`.
     *
     * @return BelongsTo<OrderPayment, $this>
     */
    public function depositPayment(): BelongsTo
    {
        return $this->belongsTo(OrderPayment::class, 'deposit_payment_id');
    }

    /**
     * Whether a عربون with money in it was ever asked for on this order.
     *
     * **Zero is not a عربون.** An order whose invoice is nothing still walks the deposit road —
     * «عربون مدفوع» is where the warehouse takes its work from, so every order has to be able to
     * reach it — and it parks with `0.00` written on it rather than with an empty column. But
     * there is nothing there for anybody to see in an account, so it asks for no confirmation,
     * puts no row in «عربون أُعلن ولم يُؤكَّد», and greys the tick. See `ConfirmDepositReceipt` and
     * `TransitionFields::deposit()`.
     */
    public function asksForADeposit(): bool
    {
        return $this->deposit_expected_amount !== null
            && bccomp((string) $this->deposit_expected_amount, '0', Money::SCALE) > 0;
    }

    /**
     * A عربون declared paid that nobody has confirmed yet — the accountant's queue.
     *
     * **Not a problem, and not a block.** It is the ordinary state of an order between the
     * counter saying the customer paid and somebody else checking the account, and the order
     * goes on being printed and delivered throughout. It is published so the app can draw the
     * tick, and indexed so the queue is one query.
     */
    public function awaitsDepositConfirmation(): bool
    {
        return $this->asksForADeposit()
            && $this->deposit_paid_at !== null
            && ! $this->is_deposit_received;
    }

    /**
     * Whether [$user] may tick «تأكيد استلام العربون» on this order.
     *
     * **Two answers in one, and both belong here**: the grant, and the rule that the person who
     * claimed the deposit is not the person who confirms it. Published through `OrderResource` so
     * the app greys the box with a reason rather than letting somebody tap it and be refused —
     * the enforcement itself is in `ConfirmDepositReceipt`, which is where a console command and
     * a future import meet it too.
     *
     * Clearing a confirmation is deliberately *not* asked about here: it is open to anybody
     * holding the grant, because un-ticking makes the record stricter rather than looser and
     * stranding a mistake serves nobody.
     */
    public function depositIsConfirmableBy(?User $user): bool
    {
        if ($user === null || ! $user->can(PermissionName::ConfirmDepositReceipt->value)) {
            return false;
        }

        if (! $this->asksForADeposit()) {
            return false;
        }

        return $this->deposit_claimed_by === null
            || (int) $this->deposit_claimed_by !== (int) $user->getKey();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Newest version first — the one under discussion is the one staff need.
     *
     * @return HasMany<OrderDesign, $this>
     */
    public function designs(): HasMany
    {
        return $this->hasMany(OrderDesign::class)->orderByDesc('version');
    }

    /**
     * @return HasMany<OrderStatusTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(OrderStatusTransition::class)->orderBy('id');
    }

    /**
     * The money ledger: what was paid, given back, or entered by mistake.
     *
     * **Oldest first, unlike the designs.** A ledger is read as a story — the deposit, then the
     * balance, then the correction — and a correction printed above the entry it corrects makes
     * a reader work backwards through an argument.
     *
     * Ordered by `paid_at` and then by id, because two entries can share a moment: a deposit and
     * its immediate reversal are typed a second apart and stored with the same date. The id
     * breaks that tie in the order they were written, which is the only tie-break that cannot
     * put a correction above its cause.
     *
     * @return HasMany<OrderPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class)->orderBy('paid_at')->orderBy('id');
    }

    /**
     * What is still owed on this order.
     *
     * **Three things close a debt: money collected, money the business decided not to collect,
     * and money the customer handed the courier instead of us.** All three are subtracted here,
     * which is what lets an order of 110 that took 105 and wrote off the difference reach «تم
     * التسوية» — see {@see OrderPaymentType::WriteOff} — and what lets one dispatched through
     * Nawris reach it without a write-off at all, see {@see OrderPaymentType::CarrierSettled}.
     * They remain three columns rather than one running total precisely so this method is the
     * only place they are added together: `paid_amount` never has to mean anything but cash, and
     * `written_off_amount` never has to mean anything but a loss.
     *
     * **Negative when the order is overpaid, and deliberately not floored here.** A screen wants
     * to say «زائد ٥٠» so somebody refunds it; the *payment* path floors it at zero separately,
     * because "you may pay -50 more" is not a sentence. Two readers, two right answers, and the
     * one that loses information is the one computed where it is needed.
     */
    public function remainingAmount(): string
    {
        $covered = bcadd(
            bcadd((string) $this->paid_amount, (string) $this->written_off_amount, 8),
            (string) $this->carrier_settled_amount,
            8,
        );

        return Money::round(bcsub((string) $this->grand_total, $covered, 8));
    }

    public function paymentStatus(): PaymentStatus
    {
        return PaymentStatus::for($this);
    }

    /**
     * What this order made, on the accrual side: `grand_total` less what it cost to produce.
     *
     * **Null until `total_cogs` is known**, not zero — an order that has not reached printing has
     * no cost to subtract yet, and a zero would read as "this order costs nothing to fulfil"
     * rather than "production hasn't happened". Computed here rather than cached: both inputs are
     * already cached columns, and a third one to keep in sync would only be able to disagree with
     * them.
     */
    public function grossProfit(): ?string
    {
        if ($this->total_cogs === null) {
            return null;
        }

        return Money::round(bcsub((string) $this->grand_total, (string) $this->total_cogs, 8));
    }

    /**
     * Whether this order ended without its money being accounted for.
     *
     * **The honest cost of a deliberate decision.** Settling an order does not write a ledger
     * entry — no payment is recorded except by the person who took it. Rather than invent an
     * entry nobody made, the discrepancy is surfaced: the app draws a warning, somebody records
     * what was actually collected, and the warning goes away.
     *
     * A generated entry would have hidden exactly this, which is why there isn't one.
     *
     * **The gap is no longer opened by settling.** An order that still owes anything is refused
     * the move — see {@see SettlementRequiresFullPayment} — so what
     * this now catches is the gap opened *afterwards*: a refund against a settled order takes
     * `paid_amount` back down and leaves a remainder somebody has to explain. Orders settled
     * before that guard existed keep whatever they were recorded with, and are exactly what this
     * flag was written to surface.
     */
    public function hasUnrecordedMoney(): bool
    {
        return $this->status->isFinal()
            && $this->status !== OrderStatus::Cancelled
            && bccomp($this->remainingAmount(), '0', Money::SCALE) > 0;
    }

    /**
     * The ledger entries a delete would have to reverse — **the rows themselves, never the three
     * derived columns.**
     *
     * The exact shape of {@see linesWithStockStillDrawn()} one ledger along, and it replaces the
     * `carriesMoney()` that read `paid_amount`/`written_off_amount`/`carrier_settled_amount`.
     * That reading answered a *yes/no* question, which was all the superseded §٩٫١ refusal
     * needed; §٢٫١ now asks «أيّ القيود؟» instead, and a column cannot say — it is a sum, and
     * the sum of an entry and its reversal is zero on an order that still carries two rows.
     *
     * **Credits only, and only those not already undone.** A refund is money that genuinely left
     * the drawer, so reversing it would claim it never did — {@see ReverseOrderPayment} refuses
     * one outright via {@see OrderPaymentType::isCredit()}, and this filter is what keeps the
     * delete from ever handing it one. An entry somebody already reversed by hand is skipped for
     * a harder reason: a second reversal breaks
     * `order_payments_reverses_payment_id_unique` and would read as money taken back twice.
     *
     * **The one source both the delete and its confirmation read**, which is what §٧٫١ means by
     * «ومن نفس الدفتر الذي سيعكسه الحذف»: the amount on the screen cannot differ from the amount
     * written, because there is only one query.
     *
     * `reversal` is soft-delete scoped like every other relation, which is what «حيّ» means here.
     *
     * @return EloquentCollection<int, OrderPayment>
     */
    public function liveCreditEntries(): EloquentCollection
    {
        $credits = array_map(
            fn (OrderPaymentType $type) => $type->value,
            array_filter(OrderPaymentType::cases(), fn (OrderPaymentType $type) => $type->isCredit()),
        );

        return $this->payments()
            ->whereIn('type', $credits)
            ->whereDoesntHave('reversal')
            ->get();
    }

    /**
     * The lines whose goods are still out of the warehouse — **read from the ledger, never from
     * `stock_deducted_at`.**
     *
     * That column is the wrong answer twice over: it is a fact about the *order* where the
     * question is about a *line*, and nothing ever clears it — not a cancellation's reversal, not
     * a reinstatement. An order cancelled after its stock left, and therefore already credited
     * back, still reads «خُصم منها مخزون» for ever. A delete built on it would promise to return
     * goods that are on the shelf already, try to reverse a movement that has a reversal, and
     * break `stock_movements_reverses_movement_id_unique` — a raw 500 in place of a message.
     *
     * So the test is per line and in two parts: the line names the draw it made, and that draw
     * has no live reversal standing against it. `reversedBy` is soft-delete scoped like every
     * other relation, which is what «حيّ» means here — the same shape
     * `whereDoesntHave('stockMovement.reversedBy')` already carries wherever a cancelled draw has
     * to be walked past.
     *
     * A restatement at «جاهزة» leaves the old movement reversed and the column pointing at the
     * new one, so this keeps answering about the draw that actually stands.
     *
     * @return EloquentCollection<int, OrderItem>
     */
    public function linesWithStockStillDrawn(): EloquentCollection
    {
        return $this->items()
            ->whereNotNull('fulfillment_stock_movement_id')
            ->whereDoesntHave('fulfillmentStockMovement.reversedBy')
            ->get();
    }

    /**
     * Whether the delete that archived this order is the one that put its goods back.
     *
     * The single fact {@see RestoreOrder} branches on. Asked of the column rather than recomputed
     * from the ledger because the two are not the same question: «هل لهذه الطلبية عكسٌ حيّ؟» is
     * true straight after *any* reversal, so a cancelled-then-deleted order would look exactly
     * like a deleted-only one — and re-deducting for the first would take goods off the shelf
     * that this delete never returned.
     */
    public function deleteReturnedStock(): bool
    {
        return $this->delete_returned_stock_at !== null;
    }

    /**
     * Whether the lines may still be edited.
     *
     * **Open while the press is running, closed once the bags are on the shelf.** A run that is
     * being printed is exactly when a quantity gets corrected — the customer rings and asks for
     * five hundred instead of three — and the shop floor can still act on it. From «جاهزة»
     * onwards the bags exist and are counted, so changing what the order says was ordered would
     * make the invoice disagree with the shelf.
     *
     * A customer taking one product and leaving another at the counter is a real thing that
     * happens; it is recorded in BACKLOG.md rather than solved by leaving this open further.
     */
    public function itemsAreEditable(): bool
    {
        return in_array(
            $this->status,
            [
                OrderStatus::New,
                OrderStatus::Designing,
                // The status named after the problem, and for a while the one status that could
                // not fix it: an order parked on a shortage is exactly where somebody argues the
                // number — and the number now moves the invoice.
                OrderStatus::Shortage,
                OrderStatus::Printing,
            ],
            true,
        );
    }

    /**
     * The sizes still missing from this order, by label.
     *
     * **What stands between «نواقص» and the press.** «جاهزة للطباعة» tells another department the
     * goods are all here; an order still short of one size has not made that true — see
     * `ShortageMustBeResolved`.
     *
     * Returns labels rather than a bare boolean because the person refused by it is standing at
     * the shelves: «ما زال ناقصاً: 25*35» sends them somewhere, «الطلبية غير مكتملة» does not.
     * Empty means nothing is short, which is what makes it readable as a yes/no at the call site.
     *
     * @return list<string>
     */
    public function unresolvedShortages(): array
    {
        return $this->items
            ->filter(fn (OrderItem $item) => $item->shortage_quantity !== null
                && bccomp((string) $item->shortage_quantity, '0', 3) > 0)
            ->map(fn (OrderItem $item) => (string) $item->variant_label)
            ->values()
            ->all();
    }

    /**
     * What the whole order weighs, in kilograms — «كم تزن الطلبية؟».
     *
     * **Summed here rather than stored, because the column that stored it was wrong.** `orders`
     * carried a `weight_kg` a clerk typed on the way into «جاهزة»; nothing was ever computed from
     * it, it could disagree with the lines beneath it, and it went — see the 2026_08_23 migration.
     * The weight the warehouse actually recorded is per line, in {@see OrderItem::$warehouse_quantity},
     * and adding those up is the only figure that cannot drift from what left the shelf.
     *
     * **The shelf's unit decides which lines count, not the invoice's.** A run sold by the piece
     * off a pile counted by the kilo weighs something; a shelf counted in pieces does not have a
     * weight at all, and multiplying a bag count by nothing to reach one is the conversion
     * COST-TRACKING-UNIT-CONVERSION.md §4 refuses to make. So the sum is over the kilogram lines
     * and the rest are simply not part of the answer.
     *
     * **Null in two different situations, and both mean «لا يوجد وزن ليُقال».** No line comes off
     * a weighed shelf — there is no weight to state. Or one does and nobody has been near a scale
     * yet: {@see OrderItem::producedQuantity()} falls back to the sold quantity, which on such a
     * line is a piece count wearing a kilogram label, and a partial sum printed under «الوزن»
     * reads as the whole parcel's. Null is the honest answer until every weighed line has been
     * measured — that happens in one go, on the way into «جاهزة».
     */
    public function totalWeight(): ?string
    {
        // `stockUnit()` falls back to the *selling* unit when the shelf behind a line was not
        // loaded, and a fallback is not an answer to weigh an order on. `loadForDisplay()` has
        // already loaded both, so this costs nothing where it is actually read.
        $this->loadMissing(['items.variant.stockItem']);

        $weighed = $this->items->filter(
            fn (OrderItem $item) => $item->stockUnit() === PricingUnit::Kilogram,
        );

        if ($weighed->isEmpty()) {
            return null;
        }

        $unmeasured = $weighed->contains(
            fn (OrderItem $item) => $item->isStockedInAnotherUnit() && $item->warehouse_quantity === null,
        );

        if ($unmeasured) {
            return null;
        }

        return $weighed->reduce(
            fn (string $carry, OrderItem $item) => bcadd($carry, $item->producedQuantity(), 3),
            '0.000',
        );
    }

    /**
     * Whether another version of the artwork may be put on the order.
     *
     * **Open before the work starts and while it is being done; closed once the press is
     * running against it.** «قيد التصميم» *is* the artwork conversation, so it was once the only
     * status here — and that was wrong about the commonest order in the shop. A customer very
     * often arrives with the finished file, agreed long before the order was taken; there is
     * nothing to design, and the file still has to go on the order. The old rule left one way to
     * record it: send the order to the designer's queue and pull it straight back out, which
     * puts a status on the screen saying work is being done that nobody is doing and two moves
     * on the timeline standing for nothing that happened.
     *
     * So «جديدة» accepts a version too — including at the moment the order is taken, see
     * {@see CreateOrder} — and «قيد التصميم» remains what it always was: the queue for the
     * orders whose artwork does not exist yet.
     *
     * **«جاهزة للطباعة» accepts one for the same reason «جديدة» does, and must.** The short path
     * is an agreed file going on the order and the press starting; that path now runs through the
     * handover, so refusing artwork here would leave the customer's own file with nowhere to go
     * but a detour into the designer's queue and straight back out — the exact walk this rule was
     * loosened to end. The goods being weighed and off the shelf by then changes nothing about
     * the artwork: the press has not run.
     *
     * The line stops at «قيد الطباعة» because that is where it means something: the bags are
     * being printed from a settled file, and changing it is going back to design on purpose —
     * a move somebody makes and the timeline records.
     *
     * Every move that touches the artwork carries it while the order stands on the permitting
     * side of the move, which is why {@see ChangeOrderStatus} writes the status before the
     * attachment in one direction and after it in the other.
     *
     * **A different line from {@see itemsAreEditable()}, deliberately.** A quantity is a number
     * the shop floor can still act on; a design is a decision that has already been acted on.
     */
    public function designsAreEditable(): bool
    {
        return in_array(
            $this->status,
            [OrderStatus::New, OrderStatus::ReadyToPrint, OrderStatus::Designing],
            true,
        );
    }

    /**
     * Whether any line is still waiting to be quoted.
     *
     * **The one question every money reader must ask of an order in «بانتظار المراجعة».** A
     * product priced «حسب الطلب» reaches this API from the customer app with no price — the app
     * is never told one and must not invent one — so the line is written null and the shop names
     * the figure on the move that accepts the request.
     *
     * While that is true the order's `items_total` and `grand_total` are understatements, and
     * the resources send null instead of them rather than show a customer a total that is not
     * the price. {@see \App\Domain\Order\Actions\RecalculateOrderTotals} explains why the stored
     * columns are allowed to be wrong, and {@see \App\Domain\Order\Actions\ChangeOrderStatus}
     * is what keeps the wrongness confined to a status nothing bills from.
     */
    public function hasUnpricedLines(): bool
    {
        return $this->items->contains(fn (OrderItem $item) => ! $item->isPriced());
    }

    /**
     * Whether the destination may still be changed.
     *
     * **Refused in exactly one open status: «جاري التوصيل».** That is the only moment the
     * address on our screen and the address on the label can part company while somebody is
     * acting on the label — the parcel is moving, and only the label is real.
     *
     * The three returns used to be refused too, on the same reasoning. They are open again
     * because the reasoning did not survive the return chain: a parcel at «راجع لدى المندوب» is
     * on its way *back to us*, and the commonest thing said about it is «ابعثها للفرع الثاني
     * بدل ما ترجع». Refusing that meant the address was corrected after the re-send instead of
     * before it, which is the same edit made later and read by nobody.
     *
     * Closed rather than final, because those two came apart: «تم الاستلام» has a move left —
     * the money — but the bags are with the customer, so its address is history.
     */
    public function destinationIsEditable(): bool
    {
        return $this->status !== OrderStatus::OutForDelivery && ! $this->status->isClosed();
    }

    /**
     * Where a cancelled order stood before it was written off, or null.
     *
     * **Read from the timeline, because that is the only place the answer exists.** «إلغاء تام»
     * is reachable from eight different statuses, and `orders` keeps no column saying which one
     * this order came from — `order_status_transitions` does, on the row the cancellation wrote.
     * It is what {@see ReinstateCancelledOrder} puts the order back
     * to, and the reason that action lets nobody name a destination.
     *
     * **The last cancellation, not the first.** An order may be written off, put back, and
     * written off again from somewhere else entirely; the move being undone is the most recent
     * one, so the search walks the history backwards.
     *
     * Uses the loaded relation when there is one — the order screen loads `transitions` for its
     * timeline anyway — and queries only when there is not, so this never becomes a second read
     * per row on a list.
     */
    public function statusBeforeCancellation(): ?OrderStatus
    {
        if ($this->status !== OrderStatus::Cancelled) {
            return null;
        }

        $transitions = $this->relationLoaded('transitions')
            ? $this->transitions->sortByDesc('id')
            : $this->transitions()->reorder('id', 'desc')->get();

        foreach ($transitions as $transition) {
            if ($transition->to_status === OrderStatus::Cancelled) {
                return $transition->from_status;
            }
        }

        return null;
    }

    /**
     * The moves this order may make, as *choices a person makes*, narrowed to those the given
     * user may actually make.
     *
     * The app draws its buttons from this rather than from a copy of the rules, which is what
     * stops a screen offering an action the server will refuse.
     *
     * **The two dispatch statuses collapse into the one the destination implies.** The
     * transition map legitimately lists both — either is a legal target — but a *button* for
     * each would put «استلام مكتب» and «جاري التوصيل» side by side on a screen where tapping
     * either produces whichever the city says, so one of the two would appear to do nothing.
     * The clerk's decision is "it is leaving"; the address settles the rest.
     *
     * Done here rather than in the resource so every reader gets the same answer — a second
     * caller building its own list is how the screen and the server start disagreeing.
     *
     * @return list<OrderStatus>
     */
    public function availableTransitionsFor(?User $user): array
    {
        $dispatch = OrderStatus::dispatchFor($this->fulfilment_type);

        $targets = array_map(
            fn (OrderStatus $target) => $target->isDispatch() ? $dispatch : $target,
            // **Both facts about this order, and neither is its status.** The destination
            // collapses the dispatch pair below; the flow decides whether the designer and the
            // press are on this order's road at all — an order of ready-made goods is offered
            // «جاهزة» from «جديدة» and is never shown two buttons for work nobody will do.
            $this->status->allowedNext($this->production_flow),
        );

        // array_unique keeps the first occurrence, so the collapsed pair leaves one entry in
        // the position the map put it — the order of the buttons stays deliberate.
        $targets = array_unique($targets, SORT_REGULAR);

        return array_values(array_filter(
            $targets,
            fn (OrderStatus $target) => $user?->can($target->permission()->value) ?? false,
        ));
    }

    /**
     * The order's journey, as steps a progress bar can draw.
     *
     * Each entry says which status, what to call it, and where the order stands relative to it —
     * `done`, `current`, or `upcoming`. The **order** of the list is the domain's, not a
     * client's: which status follows which is exactly the knowledge this app refuses to keep two
     * copies of, so it is answered here and shipped with the order.
     *
     * **A detour is reported, not hidden.** An order sitting in «نواقص» or a راجع is nowhere on
     * the main line, so every step it has genuinely passed is marked done, the rest upcoming,
     * and nothing is marked current — the screen shows the detour beside the line instead of
     * pretending the order is on it. `isDetour` on the payload is what tells it to.
     *
     * A cancelled order keeps whatever it had reached: the bar stops where the work stopped,
     * which is the honest picture of an order written off halfway.
     *
     * @return array{steps: list<array{status: string, label: string, state: string}>, is_detour: bool}
     */
    public function progress(): array
    {
        // The same pair `availableTransitionsFor()` reads, and for the same reason: an order
        // that skips production draws five steps rather than seven, instead of two steps it will
        // never reach sitting on the bar claiming it is a third of the way through.
        $line = OrderStatus::mainLine($this->fulfilment_type, $this->production_flow);
        $position = $this->status->mainLinePosition($this->fulfilment_type, $this->production_flow);

        // A detour has no position of its own, so "how far did it get" comes from the furthest
        // main-line step its timeline actually recorded. Without this an order returned from
        // the road would draw an empty bar, as though nothing had ever happened to it.
        $reached = $position ?? $this->furthestMainLineStep($line);

        $steps = [];

        foreach ($line as $index => $status) {
            $steps[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'state' => match (true) {
                    $index < $reached => 'done',
                    $index === $reached && $position !== null => 'current',
                    $index === $reached => 'done',
                    default => 'upcoming',
                },
            ];
        }

        return ['steps' => $steps, 'is_detour' => $position === null];
    }

    /**
     * The furthest main-line step this order has actually been in, read from its timeline.
     *
     * @param  list<OrderStatus>  $line
     */
    private function furthestMainLineStep(array $line): int
    {
        $visited = $this->transitions()->pluck('to_status')->all();
        $furthest = 0;

        foreach ($visited as $status) {
            $index = array_search(
                $status instanceof OrderStatus ? $status : OrderStatus::from((string) $status),
                $line,
                true,
            );

            if ($index !== false && $index > $furthest) {
                $furthest = $index;
            }
        }

        return $furthest;
    }

    /**
     * An order's history is the whole job's: its lines, its designs and every status move.
     *
     * "Who cancelled this, and when did it go out?" is what the endpoint exists to answer, and
     * those facts live on three other tables. Making the client fetch four histories and merge
     * them would push the shape of our schema into its code.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [
            $this->getMorphClass() => [$this->getKey()],
            (new OrderItem)->getMorphClass() => $this->items()->withTrashed()->pluck('id')->all(),
            (new OrderDesign)->getMorphClass() => $this->designs()->withTrashed()->pluck('id')->all(),
            (new OrderStatusTransition)->getMorphClass() => $this->transitions()->withTrashed()->pluck('id')->all(),
            // The ledger belongs in the order's story for the same reason the lines do — «من
            // ألغى دفعة الـ٥٠٠؟» is asked of the order, not of a table nobody knows the name of.
            (new OrderPayment)->getMorphClass() => $this->payments()->withTrashed()->pluck('id')->all(),
        ];
    }
}
