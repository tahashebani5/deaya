<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\StockItemGroupInUse;
use App\Domain\Inventory\Models\StockItemGroup;

/**
 * Deletes a material nothing points at.
 *
 * The guard is here rather than in the controller so it holds for every caller — a console
 * command, an import, a future bulk tidy-up — and not only for the HTTP route where somebody
 * remembered to write it.
 *
 * Both sides are counted in one message rather than reported one at a time: a material is usually
 * held back by *both* — its sizes and the products made of it — and answering «فيه مواد» only to
 * answer «فيه منتجات» on the next attempt wastes the operator's time.
 *
 * Trashed rows count on both sides, the same reasoning {@see StockItem::isUsedByAnyVariant()}
 * carries: a deleted product or size can be restored, and it would come back pointing at nothing.
 *
 * Soft, like every delete here.
 */
final class DeleteStockItemGroup
{
    public function __invoke(StockItemGroup $group): void
    {
        $items = $group->items()->withTrashed()->count();
        $products = $group->products()->withTrashed()->count();

        if ($items > 0 || $products > 0) {
            throw StockItemGroupInUse::make($group->name, $items, $products);
        }

        $group->delete();
    }
}
