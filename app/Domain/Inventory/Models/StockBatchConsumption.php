<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Inventory\Actions\ConsumeStockBatchesFifo;
use Database\Factories\StockBatchConsumptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One FIFO draw against one {@see StockBatch} — the ledger row that explains why
 * `quantity_remaining` went down.
 *
 * **Nothing updates or deletes one.** Written once, by {@see ConsumeStockBatchesFifo}, inside the
 * same transaction and under the same lock as the batch it draws from and the
 * {@see StockMovement} it belongs to. Reversing a movement is a further correction, never an edit
 * to this row — the same rule `stock_movements` and `order_payments` follow.
 */
#[UseFactory(StockBatchConsumptionFactory::class)]
#[Fillable([])]
class StockBatchConsumption extends Model
{
    /** @use HasFactory<StockBatchConsumptionFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:3',
            'total_cost' => 'decimal:2',
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
     * The movement this draw belongs to. One movement can produce several of these — the oldest
     * batch is not always enough to cover it alone.
     *
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
