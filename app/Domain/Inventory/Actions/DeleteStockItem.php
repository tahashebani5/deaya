<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Exceptions\StockItemInUseByVariants;
use App\Domain\Inventory\Exceptions\StockItemStillHeldInWarehouse;
use App\Domain\Inventory\Models\StockItem;

/**
 * Deletes a shelf nothing is on and nothing points at.
 *
 * Both guards are here rather than in the controller so they hold for every caller — a console
 * command, an import, a future bulk tidy-up — and not only for the HTTP route where somebody
 * remembered to write them.
 *
 * **Quantity first, then variants.** They fail for different reasons and the storekeeper can act
 * on only one of them at a time: stock on a shelf is a physical problem, a size still pointing at
 * it is a catalogue one. Reporting the physical one first matches the order they can be fixed in.
 *
 * Soft, like every delete here: the balances that reach zero, the ledger that explains them and
 * the item's own history all survive, and an item removed by mistake is restorable straight from
 * the database.
 */
final class DeleteStockItem
{
    public function __invoke(StockItem $item): void
    {
        if ($item->isHeldInAnyWarehouse()) {
            throw StockItemStillHeldInWarehouse::make($item->displayName());
        }

        $variants = $item->variants()->withTrashed()->count();

        if ($variants > 0) {
            throw StockItemInUseByVariants::make($item->displayName(), $variants);
        }

        $item->delete();
    }
}
