<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Actions\AllocateStockItemIdentifier;
use App\Domain\Inventory\Actions\DeleteStockItem;
use App\Domain\Inventory\Actions\SetStockItemUnit;
use Database\Factories\StockItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A thing a warehouse holds — «كيس شحن» at 25*35, at 35*40, each its own row.
 *
 * **The shelf, and what every balance, movement and cost layer is keyed on.** Before this
 * existed, stock was keyed on the size of a *product*, so كيس شحن سادة 25*35 and كيس شحن مطبوع
 * 25*35 each kept a private balance over what was physically one pile — bought once, on one
 * purchase line, at one price. An order drawing on both weighed itself against two shelves of
 * 500 and passed, then came up short on the floor. Many variants, across products, now point at
 * one row here.
 *
 * **The size lives here.** A stock item is a material *at a size*: sharing runs across products
 * at one size, never across sizes. That is what keeps a purchase order line per-size and
 * per-price — two sizes are two rows here, so they are two lines there.
 *
 * `unit` is what the pile is counted in, and it moved here from `products.stock_unit` when this
 * table arrived. A unit is a fact about the pile: two products sharing this row must not be able
 * to disagree about whether it is counted or weighed, and while the column sat on the product
 * nothing stopped them. Fillable for the same reason `pricing_unit` is — a stock item needs one
 * to be created at all — but `UpdateStockItemRequest` deliberately carries no `unit` rule, so a
 * PUT can never change it. Past creation the only writer is {@see SetStockItemUnit}.
 *
 * `code` is deliberately absent from the fillable list: allocated by
 * {@see AllocateStockItemIdentifier} and never arriving in a request.
 *
 * `stock_item_group_id` files this size under the material it is a size *of* — «كيس شحن 25*35»
 * under «كيس شحن». Nullable: a standalone item nothing else is a size of is a real thing, and
 * forcing a group of one on it would be ceremony. When a group *is* set, `name` is the group's to
 * say — {@see UpdateStockItemGroup} renames every size with it, which is what keeps
 * `stock_items_name_size_unique` able to identify exactly one shelf.
 *
 * Genuinely deletable, which puts it with cities and warehouses. Refused while any warehouse
 * still holds it — see {@see DeleteStockItem}.
 */
#[UseFactory(StockItemFactory::class)]
#[Fillable(['name', 'width_cm', 'height_cm', 'unit', 'description', 'is_active', 'sort_order'])]
class StockItem extends Model implements HasAuditTrail
{
    /** @use HasFactory<StockItemFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * Gives every stock item its code, on whatever path created it.
     *
     * On the model rather than in `CreateStockItem`, and for the reason {@see Product}
     * gives: `code` is NOT NULL, more than one place creates a row, and there is exactly one
     * correct code for any stock item — `S` + its id — so there is no decision here for a caller
     * to make and nothing is taken from them by settling it here.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            if ($item->code === null) {
                $identifier = app(AllocateStockItemIdentifier::class)();

                $item->id = $identifier->id;
                $item->code = $identifier->code;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width_cm' => 'integer',
            'height_cm' => 'integer',
            'unit' => PricingUnit::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The material this is a size of, or null for a standalone shelf.
     *
     * @return BelongsTo<StockItemGroup, $this>
     */
    public function stockItemGroup(): BelongsTo
    {
        return $this->belongsTo(StockItemGroup::class);
    }

    /**
     * Every size, across every product, that draws on this shelf.
     *
     * Inventory reading Catalog, which is the direction dependencies are allowed to run — the
     * same one {@see WarehouseStock::stockItem()} already uses. Nothing here decides
     * anything about a variant; it answers «من يسحب من هذا الرف؟» for a screen.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Where this item is, and how much of it, one row per warehouse holding it.
     *
     * @return HasMany<WarehouseStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    /**
     * Every cost layer ever opened for this item, in any warehouse.
     *
     * @return HasMany<StockBatch, $this>
     */
    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    /**
     * The last layer of this material anybody actually put a price on.
     *
     * **The figure the arrival form offers as a suggestion — and never applies.** A cost filled
     * in by the server would stop layers opening at zero, and a layer opening at zero is the only
     * signal that anybody was *meant* to price the stock. So this travels to the client, the
     * client shows it with its date, and a person taps it or does not. See
     * STOCK-COST-FROM-INVENTORY.md §8.
     *
     * Across every warehouse: the shelf a delivery happens to land on says nothing about what
     * the material is worth. Null when nothing was ever priced, which is exactly when there is
     * nothing to suggest.
     *
     * **A relation rather than a method the resource calls**, so it is eager-loaded on the one
     * response that needs it and a fifteen-row listing does not run fifteen queries to draw a
     * hint nobody is reading.
     *
     * @return HasOne<StockBatch, $this>
     */
    public function latestCostedBatch(): HasOne
    {
        return $this->hasOne(StockBatch::class)
            ->where('unit_cost', '>', 0)
            ->ofMany(['received_at' => 'max', 'id' => 'max'], fn ($q) => $q->where('unit_cost', '>', 0));
    }

    /**
     * What a picker shows: «كيس شحن 25*35», or just «كيس شحن» for an item with no dimensions.
     *
     * Composed rather than stored, so renaming the material renames every shelf at once. The
     * separator matches the one `product_variants.label` already uses for a size.
     */
    public function displayName(): string
    {
        if ($this->width_cm === null || $this->height_cm === null) {
            return $this->name;
        }

        return "{$this->name} {$this->width_cm}*{$this->height_cm}";
    }

    /**
     * Whether anything is still on a shelf anywhere.
     *
     * Rows at zero do not count, exactly as {@see Warehouse::holdsStock()} treats them: an item
     * that was here and has all been used up leaves a balance row behind, and refusing to delete
     * over a balance of 0.000 would make the destroy route unusable after the first month.
     */
    public function isHeldInAnyWarehouse(): bool
    {
        return $this->stocks()->where('quantity', '>', 0)->exists();
    }

    /**
     * Whether any product size still points at this shelf.
     *
     * Counts soft-deleted variants too, deliberately — the same reasoning
     * {@see ProductCategory::isInUse()} gives: a deleted variant can
     * come back, and it would come back pointing at nothing.
     */
    public function isUsedByAnyVariant(): bool
    {
        return $this->variants()->withTrashed()->exists();
    }

    /**
     * A stock item's history is its own plus the alert thresholds set on its shelves.
     *
     * **The movements are deliberately not here**, for the reason {@see Warehouse::auditTrailSubjects()}
     * gives: a ledger grows for as long as the business trades, and plucking every id would
     * degrade a little more each month. `GET /stock-movements?stock_item_id=` pages and filters
     * properly, and that is where to read it.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [
            $this->getMorphClass() => [$this->getKey()],
            (new WarehouseStock)->getMorphClass() => $this->stocks()->withTrashed()->pluck('id')->all(),
        ];
    }
}
