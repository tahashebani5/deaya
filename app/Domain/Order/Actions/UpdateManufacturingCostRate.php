<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\DTOs\ManufacturingCostRateData;
use App\Domain\Order\Models\ManufacturingCostRate;

/**
 * Replaces a rate with what was sent.
 *
 * Changing it only affects orders costed *after* this runs — see the migration's docblock. An
 * order already costed keeps the rate it was actually charged, snapshotted onto its
 * `production_cost_entries` row.
 */
final class UpdateManufacturingCostRate
{
    public function __invoke(ManufacturingCostRate $rate, ManufacturingCostRateData $data): ManufacturingCostRate
    {
        $rate->update([
            'product_id' => $data->productId,
            'product_variant_id' => $data->productVariantId,
            'cost_type' => $data->costType,
            'rate_per_unit' => $data->ratePerUnit,
            'is_active' => $data->isActive,
            'notes' => $data->notes,
        ]);

        return $rate;
    }
}
