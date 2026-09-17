<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Shortage\Actions\AllocateShortageIdentifier;
use App\Domain\Shortage\Actions\RecalculateShortageTotals;
use App\Domain\Shortage\Actions\SyncShortagesFromOrder;
use App\Domain\Shortage\Enums\ShortageSource;
use App\Domain\Shortage\Enums\ShortageStatus;
use Database\Factories\ShortageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing the shop is short of, and the chase to get it.
 *
 * `new → searching → unavailable | completed`, and «مكتمل» is the arithmetic's to write rather
 * than anybody's to choose — see {@see ShortageStatus}.
 *
 * **Six columns are missing from the fillable list, and each is missing for its own reason.**
 * `code` and `source` are identity assigned once at creation; `status` changes only through
 * `ChangeShortageStatus` or the totals; `assigned_to_user_id`
 * has its own action and its own permission, because whoever routes work is not whoever records
 * it; and `supplied_quantity` / `total_paid` are caches with a single writer,
 * {@see RecalculateShortageTotals}. The same rule `PurchaseOrder` follows for `vendor_id` and
 * `status`.
 *
 * `required_quantity` *is* fillable, and only half the time: {@see isEditable()} is what stops a
 * clerk editing the requirement on an order-born shortage, because there the order line is the
 * authority and this row is derived from it.
 */
#[UseFactory(ShortageFactory::class)]
#[Fillable(['name', 'unit', 'required_quantity', 'description', 'product_id', 'product_variant_id'])]
class Shortage extends Model implements HasAuditTrail
{
    /** @use HasFactory<ShortageFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * The code is allocated here rather than in {@see CreateShortage}, for the reason orders and
     * products both settled on: three places create one — the action, the sync and the factory —
     * `code` is NOT NULL, and an allocation living in only one of them is a crash waiting for the
     * next caller. There is exactly one correct code for any shortage, so settling it here takes
     * nothing away from anybody.
     */
    protected static function booted(): void
    {
        static::creating(function (self $shortage): void {
            if ($shortage->code === null) {
                $identifier = app(AllocateShortageIdentifier::class)();

                $shortage->id = $identifier->id;
                $shortage->code = $identifier->code;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => ShortageSource::class,
            'status' => ShortageStatus::class,
            'unit' => PricingUnit::class,
            // Strings all the way, three places, like every quantity it is compared against: a
            // shortage is measured off the same scale the order line was.
            'required_quantity' => 'decimal:3',
            'supplied_quantity' => 'decimal:3',
            // Money, so two — and never a float, because this one is summed across a screen.
            'total_paid' => 'decimal:2',
        ];
    }

    /**
     * What is still missing.
     *
     * **Derived, never stored.** A third number beside `required_quantity` and
     * `supplied_quantity` is the third thing that can disagree with the other two, and it would
     * be written by the same action that writes them — so it would only ever be a slower way of
     * doing this subtraction.
     *
     * Floored at zero. The bound belongs to validation, which refuses a supply larger than what
     * is left, but a negative remainder is bad enough that the arithmetic refuses it too — the
     * same pairing `OrderItem::billableQuantity()` uses.
     */
    public function remainingQuantity(): string
    {
        $remaining = bcsub(
            (string) $this->required_quantity,
            (string) $this->supplied_quantity,
            3,
        );

        return bccomp($remaining, '0', 3) < 0 ? '0.000' : $remaining;
    }

    /** Whether the whole requirement has been met — what makes «مكتمل» true. */
    public function isFullySupplied(): bool
    {
        return bccomp($this->remainingQuantity(), '0', 3) <= 0;
    }

    /**
     * Whether what is short may still be *described* differently.
     *
     * Only a manual shortage. An order-born one takes its name, its unit and its requirement from
     * the line — {@see SyncShortagesFromOrder} rewrites the requirement on every sync — so an
     * edit here would be overwritten by the next order screen save, which is worse than being
     * refused: the employee would watch their correction disappear and not know why.
     *
     * The chase itself is editable on both: status, assignee and supplies belong to this section
     * whatever the shortage came from.
     */
    public function isEditable(): bool
    {
        return $this->source === ShortageSource::Manual;
    }

    /**
     * Whether what is short is a thing the warehouse can hold.
     *
     * **The fork that decides what recording a supply does.** A shortage naming a size resolves
     * through `ProductVariant` to a stock item, so the sacks bought to cover it are posted onto a
     * shelf and open a cost layer — which is what lets the order draw them at «جاهزة» and what
     * carries the money into `cost_of_goods_sold`. One written down by hand for «شريط لاصق
     * عريض» resolves to nothing, so its purchase is recorded as money alone.
     *
     * Read off `product_variant_id` rather than by asking Inventory: the variant is the only
     * thing that can lead to a shelf, and a shortage that has one might still turn out to have a
     * variant with no stock item behind it — that is `InventoryService::stockItemFor()`'s refusal
     * to make, with its own message naming the product, not a boolean this method can improve on.
     */
    public function isStockable(): bool
    {
        return $this->product_variant_id !== null;
    }

    /**
     * Whether this row is about an order that has been archived.
     *
     * **What `ShortageListQuery` and the detail guard both ask.** A shortage carries `order_id`
     * and a customer, so a screen that renders them answers for orders the archive itself refuses
     * to show — the back door `ArchivedOrdersNeedTheArchiveGrant` was written to close in front
     * of `logs.view`, reopened from a new direction. See SHORTAGES-DESIGN §٥.
     *
     * Reads the loaded relation rather than querying, which is why {@see self::order()} is declared
     * `withTrashed()`: the soft-delete-scoped version would hydrate null for exactly the rows
     * this question is about, and the honest answer would become indistinguishable from «لا
     * طلبية لها». A list that eager-loads `order` answers this for a whole page without a query
     * per row, and `shouldBeStrict()` makes a caller that forgot the eager load fail loudly
     * rather than N+1 in silence.
     *
     * The list does not call this at all — it filters archived rows out in SQL, because a page
     * that fetched them and then dropped them would paginate to short pages.
     */
    public function belongsToAnArchivedOrder(): bool
    {
        return $this->order?->trashed() === true;
    }

    /**
     * Every supply ever recorded, reversals included.
     *
     * @return HasMany<ShortageSupply, $this>
     */
    public function supplies(): HasMany
    {
        return $this->hasMany(ShortageSupply::class);
    }

    /**
     * The entries that still count — what the totals are built from.
     *
     * A supply with a live reversal standing against it, and the reversal itself, are both out:
     * the pair nets to nothing, and leaving either in would count a purchase that was undone.
     * `reversedBy` is soft-delete scoped like every other relation, which is what «حيّة» means
     * here — the shape `Order::liveCreditEntries()` uses.
     *
     * @return EloquentCollection<int, ShortageSupply>
     */
    public function liveSupplies(): EloquentCollection
    {
        return $this->supplies()
            ->whereNull('reverses_supply_id')
            ->whereDoesntHave('reversedBy')
            ->get();
    }

    /**
     * What has actually come back, summed from the ledger rather than read off the cache.
     *
     * **The one definition of that number**, used by {@see RecalculateShortageTotals} to write
     * `supplied_quantity` and by {@see SyncShortagesFromOrder} to restate the requirement in the
     * same breath as it writes a new entry — the one moment the cache is a statement behind the
     * ledger.
     *
     * A plain `supplies()->sum('quantity')` is the trap this exists to close: a reversal is a row
     * with a *positive* quantity, so summing the table counts a purchase and its undoing as two
     * arrivals, and a requirement restated from it would grow every time an entry was corrected.
     * {@see liveSupplies()} is what "actually" means.
     */
    public function liveSuppliedQuantity(): string
    {
        $quantity = '0.000';

        foreach ($this->liveSupplies() as $supply) {
            $quantity = bcadd($quantity, (string) $supply->quantity, 3);
        }

        return $quantity;
    }

    /**
     * The order this came off — **archived ones included**.
     *
     * `withTrashed()` because a shortage belongs to the order it belongs to whether or not
     * somebody has since archived that order, and the scoped version would answer null for the
     * rows {@see belongsToAnArchivedOrder()} exists to identify. Visibility is decided one layer
     * up — in `ShortageListQuery` and in the detail guard — where the reader's grants are known;
     * a relation cannot know them, and a relation that hid rows would make the guard look
     * redundant to the next person who reads it.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * The line this came off.
     *
     * Null on a manual shortage, and null again once the line is deleted off its order — the
     * shortage outlives it, carrying its money and its snapshotted name. See
     * {@see SyncShortagesFromOrder}.
     *
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The entries go with it.
     *
     * **Unlike `Order`, which cascades nothing** — see `CascadesSoftDeletes` for the mechanical
     * reason there. Here the calculation is the other way round: nothing outside this row reads
     * its supplies, so following the parent costs nothing and leaving them behind would leave
     * orphan money that `RecalculateShortageTotals` would still find if the shortage were ever
     * restored.
     *
     * @return list<string>
     */
    public function softDeleteCascades(): array
    {
        return ['supplies'];
    }
}
