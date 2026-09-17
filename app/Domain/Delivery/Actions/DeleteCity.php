<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Concerns\CascadesSoftDeletes;
use App\Domain\Delivery\Models\City;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a city and the regions inside it, atomically.
 *
 * The cascade itself is not here — it lives on the model, in {@see CascadesSoftDeletes}, so that
 * *every* path that deletes a city takes its regions and not only this one. What this class adds
 * is the transaction: the city and its neighbourhoods leave together or not at all.
 *
 * That matters more than it looks. A city holds tens of regions, each deleted through its own
 * model so the audit trail records it. Without a transaction, a failure partway through would
 * leave a live city with half its map missing — and the customer picking from it would have no
 * way of knowing.
 */
final class DeleteCity
{
    public function __invoke(City $city): void
    {
        DB::transaction(fn () => $city->delete());
    }
}
