<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Concerns\CascadesSoftDeletes;
use App\Domain\Inventory\Exceptions\WarehouseStillHoldsStock;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an empty warehouse and the balance lines it left behind, atomically.
 *
 * The cascade is not here — it lives on the model, in {@see CascadesSoftDeletes}, so that *every*
 * path that deletes a warehouse takes its balance rows and not only this one. What this class
 * adds is the check and the transaction.
 *
 * The check is the interesting half. A warehouse still holding stock cannot be deleted, because
 * the alternative is a balance sitting inside a place the API says does not exist — invisible to
 * every listing, still counted by the ledger, and discovered by whoever is trying to reconcile
 * months later. Zero-quantity rows do not block it: a size that was here and has all been used
 * up leaves a row behind, and treating that as "holds stock" would make the route unusable after
 * the first month.
 */
final class DeleteWarehouse
{
    public function __invoke(Warehouse $warehouse): void
    {
        DB::transaction(function () use ($warehouse): void {
            // Inside the transaction, so a movement committing between the check and the delete
            // cannot slip stock into a warehouse that is on its way out.
            if ($warehouse->holdsStock()) {
                throw WarehouseStillHoldsStock::make();
            }

            $warehouse->delete();
        });
    }
}
