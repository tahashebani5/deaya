<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\DTOs\WarehouseData;
use App\Domain\Inventory\Models\Warehouse;

/**
 * The whole warehouse is replaced by what was sent, so omitting `location` clears it rather than
 * keeping it. That is what PUT means, and it keeps "save the form" from silently preserving an
 * address the user just deleted.
 *
 * What it never touches is the stock inside. Retyping a warehouse from `main` to `operational` says
 * what the place is used for; it does not move a single bag, and there is no version of this
 * action that should.
 */
final class UpdateWarehouse
{
    public function __invoke(Warehouse $warehouse, WarehouseData $data): Warehouse
    {
        $warehouse->update([
            'name' => $data->name,
            'type' => $data->type,
            'location' => $data->location,
        ]);

        return $warehouse->loadCount('stocks');
    }
}
