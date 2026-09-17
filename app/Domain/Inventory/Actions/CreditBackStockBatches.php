<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchConsumption;
use Illuminate\Support\Facades\DB;

/**
 * Adds a quantity back to the exact cost layers a movement drew it from.
 *
 * Called only from {@see ApplyStockChange::creditBack()}, after the balance row is already locked
 * and grown — never on its own. `stock_batch_consumptions` rows are never edited or deleted (see
 * that model's docblock), so the movement being reversed still names, permanently, which batches
 * to credit and by how much each.
 *
 * **Except the layers somebody has already been paid for.** A printed line buys its plain bags
 * off the deal that financed them the moment they leave the shelf, and the investor's money is
 * in his ledger before the parcel is on a van. Crediting those back to his layers would give him
 * the goods *and* the money, and the next order would pay him for the same kilo twice — so with
 * `$purchasedLayersBelongToTheCompany` those draws are handed back to the caller instead, which
 * opens them as the company's own stock at what the company paid. «استلم الزبون ما استلمش،
 * المطبعة تتحمّل.»
 *
 * The flag is off for every other reversal, and one in particular: a **restatement** — the press
 * correcting what the run actually used — credits everything back to the layers it came from,
 * because it is undoing the draw rather than writing off a sale.
 */
final class CreditBackStockBatches
{
    /**
     * @return list<array{quantity: string, printing_sale_price: string}> the draws that were
     *                                                                    deliberately not credited back
     */
    public function __invoke(int $stockMovementId, bool $purchasedLayersBelongToTheCompany = false): array
    {
        $totals = StockBatchConsumption::query()
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_batch_consumptions.stock_batch_id')
            ->where('stock_batch_consumptions.stock_movement_id', $stockMovementId)
            ->groupBy('stock_batch_consumptions.stock_batch_id', 'stock_batches.printing_sale_price')
            ->get([
                'stock_batch_consumptions.stock_batch_id',
                'stock_batches.printing_sale_price',
                DB::raw('SUM(stock_batch_consumptions.quantity) as total_quantity'),
            ]);

        $bought = [];

        if ($purchasedLayersBelongToTheCompany) {
            [$purchased, $totals] = $totals->partition(
                fn ($row) => $row->printing_sale_price !== null,
            );

            foreach ($purchased as $row) {
                $bought[] = [
                    'quantity' => (string) $row->total_quantity,
                    'printing_sale_price' => (string) $row->printing_sale_price,
                ];
            }
        }

        // Locked in ascending id order — the same deadlock-avoidance reasoning
        // RecordStockMovement::moveTransferBalances() already documents, in case two reversals
        // ever touch an overlapping set of batches at once.
        $batches = StockBatch::query()
            ->whereIn('id', $totals->pluck('stock_batch_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($totals as $row) {
            $batch = $batches[$row->stock_batch_id];
            $batch->quantity_remaining = bcadd((string) $batch->quantity_remaining, (string) $row->total_quantity, 3);
            $batch->save();
        }

        return $bought;
    }
}
