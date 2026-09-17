<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Audit\Concerns\Auditable;
use Database\Factories\ProductPriceTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * "From this quantity upward, one unit costs this much."
 *
 * The most audit-worthy table in the catalogue: this is where the number a customer is charged
 * comes from, so "who changed this price, and from what" has to be answerable. Its entries are
 * read through the product that owns it.
 */
#[UseFactory(ProductPriceTierFactory::class)]
#[Fillable(['min_quantity', 'unit_price'])]
class ProductPriceTier extends Model
{
    /** @use HasFactory<ProductPriceTierFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * decimal, never float — these values are multiplied by quantities and compared.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
