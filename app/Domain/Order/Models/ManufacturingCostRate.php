<?php

declare(strict_types=1);

namespace App\Domain\Order\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Order\Actions\ApplyManufacturingRates;
use App\Domain\Order\Enums\ManufacturingCostType;
use Database\Factories\ManufacturingCostRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a unit of production standard-costs at — for one exact size, for every size of one
 * product, or for everything that has neither.
 *
 * Reference data the business curates, the same shape `BusinessField` already is — a short list
 * edited from a screen, applied automatically rather than typed per job. See
 * {@see ApplyManufacturingRates}, the only reader, for the three-tier lookup order.
 *
 * **A row sets at most one of `product_variant_id`/`product_id`** — the database enforces this
 * with a CHECK, not only validation. Which tier a row belongs to has to be readable from which
 * column is filled: both null is the default, `product_id` alone is a product-wide rate, and
 * `product_variant_id` alone is specific to that one size. A row naming both would be ambiguous
 * about which it actually is.
 */
#[UseFactory(ManufacturingCostRateFactory::class)]
#[Fillable(['product_id', 'product_variant_id', 'cost_type', 'rate_per_unit', 'is_active', 'notes'])]
class ManufacturingCostRate extends Model implements HasAuditTrail
{
    /** @use HasFactory<ManufacturingCostRateFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_type' => ManufacturingCostType::class,
            'rate_per_unit' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The product this rate applies to every size of. Null on a variant-specific rate (the
     * variant's own product is reached through the `productVariant()` relation instead) and on
     * the default.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The one size this rate is specific to. Null on a product-wide rate and on the default.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
