<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Queries;

use App\Domain\Inventory\Models\StockBatchConsumption;

/**
 * What each draw of a movement took, and which cost layer it came out of.
 *
 * Exposed for whoever needs to attribute one movement's cost to whoever financed the layers
 * behind it. Inventory itself has no opinion about that: `investor_deal_id` is a nullable column
 * it copies and never reads, and this query hands it out as a plain integer.
 *
 * **Every row of the movement comes back, funded or not.** A caller that filtered to one funder
 * here and then split a line's revenue across what was left would hand that funder the whole
 * line: a proportional split distributes its total across whatever weights it is given, so
 * dropping the company's rows first turns 1,000 units out of 3,000 into 100% of the money.
 */
final class ConsumptionBreakdownQuery
{
    /**
     * @param  list<int>  $movementIds
     * @return array<int, list<array{
     *     consumption_id: int,
     *     stock_batch_id: int,
     *     investor_deal_id: ?int,
     *     printing_sale_price: ?string,
     *     quantity: string,
     *     unit_cost: string,
     *     total_cost: string
     * }>>  keyed by movement id
     */
    public function __invoke(array $movementIds): array
    {
        if ($movementIds === []) {
            return [];
        }

        $rows = StockBatchConsumption::query()
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_batch_consumptions.stock_batch_id')
            ->whereIn('stock_batch_consumptions.stock_movement_id', $movementIds)
            ->whereNull('stock_batches.deleted_at')
            ->orderBy('stock_batch_consumptions.id')
            ->get([
                'stock_batch_consumptions.id as consumption_id',
                'stock_batch_consumptions.stock_movement_id',
                'stock_batch_consumptions.stock_batch_id',
                'stock_batch_consumptions.quantity',
                'stock_batch_consumptions.unit_cost',
                'stock_batch_consumptions.total_cost',
                'stock_batches.investor_deal_id',
                'stock_batches.printing_sale_price',
            ]);

        $byMovement = [];

        foreach ($rows as $row) {
            $byMovement[(int) $row->stock_movement_id][] = [
                'consumption_id' => (int) $row->consumption_id,
                'stock_batch_id' => (int) $row->stock_batch_id,
                'investor_deal_id' => $row->investor_deal_id === null ? null : (int) $row->investor_deal_id,
                // Copied and never read here, exactly as `investor_deal_id` is: what the layer's
                // financier sells it to the press for. Orders prices a printed line's material
                // with it; Investment turns the margin into a wallet row.
                'printing_sale_price' => $row->printing_sale_price === null ? null : (string) $row->printing_sale_price,
                'quantity' => (string) $row->quantity,
                'unit_cost' => (string) $row->unit_cost,
                'total_cost' => (string) $row->total_cost,
            ];
        }

        return $byMovement;
    }
}
