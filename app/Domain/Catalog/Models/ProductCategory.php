<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Catalog\Enums\ProductionMode;
use Database\Factories\ProductCategoryFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * التصنيف — a heading in the catalogue: أكياس, علب وكراتين التغليف, ستيكرات ومطبوعات أخرى,
 * مطبوعة, سادة.
 *
 * Reference data the business curates, exactly as {@see BusinessField} is: a short list, edited
 * from a screen, pointed at by every product. It is what the catalogue is organised by, and what
 * the products screen filters on.
 *
 * **The only thing that classifies a product**, since the last two names on that list arrived.
 * مطبوعة/سادة used to be «النوع», a column of its own on the product; it fed no calculation
 * anywhere, so it became two rows here and the column was dropped. See PRODUCT-CATEGORIES.md.
 */
#[UseFactory(ProductCategoryFactory::class)]
#[Fillable(['name', 'description', 'is_active', 'sort_order', 'parent_id', 'production_mode', 'is_investable'])]
class ProductCategory extends Model implements HasAuditTrail
{
    /** @use HasFactory<ProductCategoryFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'production_mode' => ProductionMode::class,
            'is_investable' => 'boolean',
            'sort_order' => 'integer',
            'image_width_px' => 'integer',
            'image_height_px' => 'integer',
        ];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The heading this one sits under, or null when it is a heading in its own right.
     *
     * @return BelongsTo<ProductCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The headings under this one, in the catalogue's own order.
     *
     * **One level deep.** A child may not hold children — the rule is enforced where it can be
     * explained, in {@see StoreProductCategoryRequest}, rather than by a database that can only
     * say no.
     *
     * @return HasMany<ProductCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Whether a product may be filed under this heading.
     *
     * **A heading with children is a heading, not a slot.** Allowing both would make «كم منتجاً
     * تحت أكياس؟» two different questions — «مباشرة» and «بالمجموع» — and every count on every
     * screen would have to say which one it meant.
     */
    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    /** Whether this heading may itself be given a parent — see the one-level rule. */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * How goods filed here come to exist — the effective answer, parent included.
     *
     * **What decides an order's road**, by way of `ResolveOrderFlow`. «سادة» is the heading this
     * was built for and «وسيط» is the one that made it an enum; PRODUCT-CATEGORIES.md notes that
     * the مطبوعة/سادة split fed no calculation anywhere when it became two headings, and this is
     * the calculation it now feeds.
     *
     * **A parent's answer reaches its children, and only where the child has not given one.**
     * That was an OR while this was a boolean, and it is the same rule stated for three values:
     * a child left at the default — `in_house`, what every heading is until somebody decides
     * otherwise — takes its parent's mode. Two things follow from the one-level tree and both
     * point this way: a product is filed under a leaf, so marking «سادة» and later adding «سادة
     * ورقية» beneath it must not silently drop the answer from every product that moved down;
     * and a child that *does* name a mode has been decided about, so nothing above it overrules
     * that.
     *
     * Reads `parent` through the relation, so a caller that has not loaded it gets a query
     * rather than a wrong answer; `ResolveOrderFlow` eager-loads it for exactly that reason.
     */
    public function productionMode(): ProductionMode
    {
        if ($this->production_mode !== ProductionMode::InHouse) {
            return $this->production_mode;
        }

        return $this->parent?->production_mode ?? ProductionMode::InHouse;
    }

    /**
     * Whether a deal may be opened against products filed under this heading.
     *
     * Shaped like {@see ProductionMode()} and for the same reason — the child's own answer wins
     * and the parent is the fallback — but three-state rather than two, because a boolean
     * defaulting to `false` cannot distinguish «لا» from «اسأل أبي». Without that distinction a
     * leaf deliberately excluded from an investable family («أكياس نايلون خاصة بالشركة» under an
     * investable «أكياس») would still answer yes, and by the time anyone noticed the goods would
     * have arrived and the shares frozen.
     *
     * A heading nobody has decided about is not investable: fail-closed, like every other guard
     * of this kind here.
     */
    public function isInvestable(): bool
    {
        return $this->is_investable ?? ($this->parent?->is_investable ?? false);
    }

    public function hasImage(): bool
    {
        return $this->image_disk !== null && $this->image_path !== null;
    }

    public function imageStorage(): Filesystem
    {
        return Storage::disk((string) $this->image_disk);
    }

    /**
     * Built on demand from the disk the file actually lives on, and never stored.
     *
     * A URL embeds a bucket, a region and a host, all of which change; a stored one silently
     * rots. On a disk that signs its links this hands out a temporary one, exactly as
     * {@see CustomerDesign} does.
     */
    public function imageUrl(): ?string
    {
        if (! $this->hasImage()) {
            return null;
        }

        $disk = $this->imageStorage();

        return $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl((string) $this->image_path, now()->addMinutes(config('media.temporary_url_minutes')))
            : $disk->url((string) $this->image_path);
    }

    /**
     * Whether anything points at this category — a product, directly or through a child.
     *
     * Counts soft-deleted products too, deliberately: a deleted product can be restored, and a
     * category removed in the meantime would leave it pointing at nothing.
     */
    public function isInUse(): bool
    {
        if ($this->products()->withTrashed()->exists()) {
            return true;
        }

        return $this->children()
            ->whereHas('products', fn (Builder $query) => $query->withTrashed())
            ->exists();
    }

    /**
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [$this->getMorphClass() => [$this->getKey()]];
    }
}
