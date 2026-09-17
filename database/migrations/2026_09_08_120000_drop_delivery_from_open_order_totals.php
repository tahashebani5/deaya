<?php

use App\Console\Commands\DropDeliveryFromOrderTotals;
use App\Domain\Order\Actions\RecalculateOrderTotals;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Takes the delivery fee back out of the totals of the orders that are still running.
 *
 * The fee left `grand_total` today by the owner's instruction — see
 * {@see RecalculateOrderTotals}. Every order taken before this deploy
 * still has it inside, and an order in the middle of its life cannot be left that way: the COD
 * builder no longer subtracts the fee, so the next edit of a parcel already on the road would ask
 * Nawris to collect it *and* leave the courier charging it at the door.
 *
 * **The work lives in `orders:drop-delivery-from-totals` rather than here**, following
 * {@see \App\Console\Commands\DeshapeStoredText}: it is worth running
 * `--dry-run` against the live database first and reading the two lists it prints before letting
 * it write. See {@see DropDeliveryFromOrderTotals} for what it
 * refuses to touch — closed orders above all, whose figures record what was really billed.
 *
 * Its own migration, per RULES §8: a schema migration does not carry data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('orders:drop-delivery-from-totals', ['--force' => true]);

        echo Artisan::output();
    }

    public function down(): void
    {
        // Nothing to undo that would not be a guess. Adding the fee back would have to know which
        // orders it was taken off, and — because an order edited since would be re-summed by
        // `RecalculateOrderTotals` under the new rule anyway — the addition would not survive the
        // next save. The way back is the code change, not the data.
    }
};
