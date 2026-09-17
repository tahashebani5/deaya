<?php

declare(strict_types=1);

namespace App\Domain\Order\Actions;

use App\Domain\Order\DTOs\ManufacturingCostRateData;
use App\Domain\Order\Models\ManufacturingCostRate;

final class CreateManufacturingCostRate
{
    public function __invoke(ManufacturingCostRateData $data): ManufacturingCostRate
    {
        return ManufacturingCostRate::create([
            'product_id' => $data->productId,
            'product_variant_id' => $data->productVariantId,
            'cost_type' => $data->costType,
            'rate_per_unit' => $data->ratePerUnit,
            'is_active' => $data->isActive,
            'notes' => $data->notes,
        ]);
    }
}
