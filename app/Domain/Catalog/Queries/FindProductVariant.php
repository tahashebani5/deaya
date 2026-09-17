<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\CatalogService;
use App\Domain\Catalog\Models\ProductVariant;

/**
 * One size, by id, with the product whose rules govern it.
 *
 * Exists so {@see CatalogService} can answer another context's "which
 * variant is this?" without the Service itself holding a query — the Service is the door, not a
 * place for logic.
 *
 * `findOrFail`, so a movement naming a size that does not exist is a 404 rendered by the handler
 * in bootstrap/app.php, exactly as it would be for a route-model binding. There is nothing for a
 * caller to check and nothing to forget.
 */
final class FindProductVariant
{
    public function __invoke(int $variantId): ProductVariant
    {
        return ProductVariant::query()->with('product')->findOrFail($variantId);
    }
}
