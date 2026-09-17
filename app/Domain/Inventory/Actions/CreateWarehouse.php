<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\WarehouseData;
use App\Domain\Inventory\Models\Warehouse;

final class CreateWarehouse
{
    public function __invoke(WarehouseData $data): Warehouse
    {
        $warehouse = Warehouse::create([
            'name' => $data->name,
            'type' => $data->type,
            'location' => $data->location,
        ]);

        // A brand-new warehouse holds nothing, but the count must still be present: the resource
        // renders it, and strict mode turns a missing attribute into an exception rather than a
        // null.
        return $warehouse->loadCount('stocks');
    }
}
