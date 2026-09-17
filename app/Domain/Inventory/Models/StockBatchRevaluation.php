<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Actions\RevalueStockBatch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One decision to carry a quantity of stock at a different cost, and the reason given for it.
 *
 * **The event, not the row it changed.** A revaluation can touch two batches — repricing part of
 * a layer splits it — so «what happened here» belongs to neither of them alone. And the audit
 * trail, which records that `unit_cost` went from 0.000 to 3.500, has nowhere to put «فاتورة
 * المورد وصلت بسعر مختلف»: that is the half somebody will actually be looking for.
 *
 * **Nothing here is fillable, and nothing updates one.** Written once by
 * {@see RevalueStockBatch} inside the transaction that moved the
 * cost, under the same balance lock — the same treatment {@see StockBatchConsumption} gets, for
 * the same reason. A correction to a correction is another row.
 */
#[Fillable([])]
class StockBatchRevaluation extends Model
{
    use Auditable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'old_unit_cost' => 'decimal:3',
            'new_unit_cost' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<StockBatch, $this>
     */
    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }

    /**
     * Who decided. Named `user` rather than `employee` because it is the signed-in account that
     * is accountable for a bookkeeping change, not a role on the shop floor.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
