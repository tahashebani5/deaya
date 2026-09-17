<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\CascadesSoftDeletes;
use App\Domain\Inventory\Models\StockItem;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One buyable form of a product — a size such as 25*35, or the single سادة variant that a
 * per-kilo product carries.
 *
 * Audited; its entries are read through the product that owns it.
 */
#[UseFactory(ProductVariantFactory::class)]
#[Fillable(['stock_item_id', 'cost_price', 'label', 'width_cm', 'height_cm', 'is_active', 'sort_order'])]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use Auditable, CascadesSoftDeletes, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width_cm' => 'integer',
            'height_cm' => 'integer',
            'is_active' => 'boolean',
            // String, not float, and to the same three places as the price it is set against:
            // this number is multiplied by a quantity and copied onto an order line, which is
            // exactly where a float stops being the figure somebody typed.
            'cost_price' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The shelf this size draws from — «كيس شحن 25*35».
     *
     * Null for a size that is never stocked; a quote-only product has no pile behind it, and a
     * NOT NULL column would only teach whoever creates one to point at an unrelated row.
     *
     * **Read-only, and for rendering only.** This is a relation into Inventory, and RULES.md §3
     * says decisions cross a context boundary through its Service — so anything anyone needs to
     * *decide* about this link (which shelf does this size draw from, and what happens when it
     * has none) is asked of `InventoryService::stockItemIdFor()`, which turns a missing link into
     * a readable failure instead of a dereferenced null. This exists so a product screen can
     * eager-load the item's name in one query.
     *
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * @return HasMany<ProductPriceTier, $this>
     */
    public function priceTiers(): HasMany
    {
        return $this->hasMany(ProductPriceTier::class)->orderBy('min_quantity');
    }

    /**
     * A price break belongs to its size and nothing else.
     *
     * @return list<string>
     */
    public function softDeleteCascades(): array
    {
        return ['priceTiers'];
    }

    /**
     * The price that applies to `$quantity`: the highest tier whose floor it reaches.
     *
     * Resolved in PHP over the already-loaded relation rather than with a query, so quoting a
     * whole catalogue page does not fire one statement per row. Callers must eager-load
     * `priceTiers` — with strict mode on, forgetting to throws rather than silently degrading.
     */
    public function priceTierFor(string $quantity): ?ProductPriceTier
    {
        return $this->priceTiers
            ->filter(fn (ProductPriceTier $tier) => bccomp($quantity, (string) $tier->min_quantity, 3) >= 0)
            ->sortByDesc(fn (ProductPriceTier $tier) => (float) $tier->min_quantity)
            ->first();
    }
}
