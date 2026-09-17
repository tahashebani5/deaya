<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Catalog\CatalogService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Investor\DTOs\DealItemData;
use App\Domain\Investor\Exceptions\DealIsNotEditable;
use App\Domain\Investor\Exceptions\StockItemIsNotInvestable;
use App\Domain\Investor\Models\InvestorDeal;

/**
 * Replaces the set of shelves a deal funds.
 *
 * Each one is checked through Catalog's own door — Investment never reaches for a product model
 * — and the check is «every active product on this shelf is investable», not «any of them is».
 * See {@see CatalogService::stockItemInvestability()} for why the difference decides whether an
 * investor's money can end up financing a product he was never offered.
 */
final class SyncDealItems
{
    public function __construct(
        private readonly CatalogService $catalog,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @param  list<DealItemData>  $items
     *
     * @throws DealIsNotEditable
     * @throws StockItemIsNotInvestable
     */
    public function __invoke(InvestorDeal $deal, array $items): InvestorDeal
    {
        if (! $deal->isEditable()) {
            throw DealIsNotEditable::make((string) $deal->code);
        }

        foreach ($items as $item) {
            $verdict = $this->catalog->stockItemInvestability($item->stockItemId);

            if (! $verdict['investable']) {
                throw StockItemIsNotInvestable::make(
                    $verdict['offending_product'] ?? $this->inventory->findStockItem($item->stockItemId)->displayName()
                );
            }
        }

        $keep = array_map(fn (DealItemData $item) => $item->stockItemId, $items);

        foreach ($deal->items()->get() as $existing) {
            if (! in_array((int) $existing->stock_item_id, $keep, true)) {
                $existing->delete();
            }
        }

        foreach ($items as $item) {
            // Found by its shelf, or made empty. Not `firstOrNew(['stock_item_id' => …])`:
            // that mass-assigns the key, and the key is deliberately not fillable — which shelf
            // a row funds is this action's to set and never a payload's, so strict mode refused
            // the whole create rather than let it through.
            $row = $deal->items()->where('stock_item_id', $item->stockItemId)->first()
                ?? $deal->items()->make();

            $row->fill([
                'quantity_expected' => $item->quantityExpected,
                'expected_unit_cost' => $item->expectedUnitCost,
                'expected_unit_price' => $item->expectedUnitPrice,
                'notes' => $item->notes,
            ]);

            $row->stock_item_id = $item->stockItemId;
            $row->save();
        }

        return $deal->load('items.stockItem');
    }
}
