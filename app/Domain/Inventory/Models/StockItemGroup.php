<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inventory\Actions\AllocateStockItemGroupIdentifier;
use App\Domain\Inventory\Actions\DeleteStockItemGroup;
use App\Domain\Inventory\Actions\ResolveStockItemForVariant;
use App\Domain\Inventory\Actions\SetStockItemUnit;
use App\Domain\Inventory\Actions\UpdateStockItemGroup;
use Database\Factories\StockItemGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * «التصنيف» — the family a material is filed under: «كيس شحن», «كيس ورقي».
 *
 * **Holds nothing.** No balance, no cost layer, no size. A {@see StockItem} is still the only
 * thing a warehouse can put a quantity on; this is the name every one of its sizes shares. That
 * is why it is a table of its own rather than a `parent_id` on `stock_items` — a self-referencing
 * table would make parents and children two kinds of row in one shape, and every picker in
 * Inventory would need to remember to filter one kind out.
 *
 * **What it buys.** A product points here once and stops naming shelves size by size:
 * {@see ResolveStockItemForVariant} matches each of its variants to the item of the same size
 * under this group. كيس شحن سادة and كيس شحن مطبوع both point at «كيس شحن» and land on the same
 * pile at every size they share.
 *
 * `name` is the material's, and every item under the group carries it — which is what keeps
 * `stock_items_name_size_unique` able to identify one shelf. Renaming here therefore renames them
 * all, in one transaction; see {@see UpdateStockItemGroup}.
 *
 * `default_unit` is what a size *created under* this group starts out counted in. It is
 * deliberately not the authority for an existing item: that stays on `stock_items.unit` and moves
 * only through {@see SetStockItemUnit}, under locks, because every balance and batch carries a
 * snapshot of it.
 */
#[UseFactory(StockItemGroupFactory::class)]
#[Fillable(['name', 'default_unit', 'description', 'is_active', 'sort_order'])]
class StockItemGroup extends Model implements HasAuditTrail
{
    /** @use HasFactory<StockItemGroupFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * Gives every group its code, on whatever path created it — the same reasoning
     * {@see StockItem::booted()} carries.
     */
    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            if ($group->code === null) {
                $identifier = app(AllocateStockItemGroupIdentifier::class)();

                $group->id = $identifier->id;
                $group->code = $identifier->code;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_unit' => PricingUnit::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The sizes this material comes in, smallest first — the order a picker reads them in.
     *
     * @return HasMany<StockItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockItem::class)->orderBy('width_cm')->orderBy('height_cm');
    }

    /**
     * The products made of this material.
     *
     * Inventory reading Catalog, which is the direction dependencies are allowed to run. Nothing
     * here decides anything about a product; it answers «من يصنع من هذا التصنيف؟» for a screen,
     * and it is what {@see DeleteStockItemGroup} counts.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The item of this material at exactly this size, or null if the group has not reached it.
     *
     * Null dimensions match null dimensions — a group whose material is counted without a size
     * has exactly one item, and a variant with no size resolves to it. `whereNull` rather than
     * `where(..., null)`, which compiles to `= NULL` and matches nothing.
     */
    public function itemForSize(?int $widthCm, ?int $heightCm): ?StockItem
    {
        return $this->items()
            ->when($widthCm === null, fn ($q) => $q->whereNull('width_cm'))
            ->when($widthCm !== null, fn ($q) => $q->where('width_cm', $widthCm))
            ->when($heightCm === null, fn ($q) => $q->whereNull('height_cm'))
            ->when($heightCm !== null, fn ($q) => $q->where('height_cm', $heightCm))
            ->first();
    }

    /**
     * A group's history is its own plus its sizes' — «من غيّر وحدة 25*35؟» is asked of the
     * material, and that number lives on another table.
     *
     * The balances and the ledger are deliberately not here, for the reason
     * {@see StockItem::auditTrailSubjects()} gives: one is read through the item's own screen,
     * the other through `GET /stock-movements`.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [
            $this->getMorphClass() => [$this->getKey()],
            (new StockItem)->getMorphClass() => $this->items()->withTrashed()->pluck('id')->all(),
        ];
    }
}
