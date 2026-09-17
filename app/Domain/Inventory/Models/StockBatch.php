<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Actions\ApplyStockChange;
use App\Domain\Inventory\Actions\ConsumeStockBatchesFifo;
use App\Domain\Inventory\Actions\RevalueStockBatch;
use App\Domain\Inventory\Actions\SetStockUnit;
use App\Domain\Inventory\Enums\StockBatchSourceType;
use App\Domain\Inventory\Exceptions\BatchIsFullyConsumed;
use App\Domain\Vendor\Models\StockArrivalItem;
use Database\Factories\StockBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One cost layer of one size in one warehouse — what a quantity of stock cost, and how much of
 * that quantity is still on the shelf.
 *
 * **Nothing here is fillable.** Every column is either written once by
 * {@see ApplyStockChange::increase()}/`relocateBatches()` when the layer is created, or drawn
 * down by {@see ConsumeStockBatchesFifo} under the same row lock
 * `WarehouseStock` uses — the same treatment `WarehouseStock::quantity` gets, for the same
 * reason: a payload that could move cost without leaving a `stock_batch_consumptions` row behind
 * would be exactly the gap this design exists to close.
 *
 * `unit` is the one column with a second writer: a snapshot of `product.stock_unit` on the way
 * in, like every other column here, but also changed — alongside every other batch and balance
 * for the same product's variants, in one transaction — by
 * {@see SetStockUnit}. Still never reachable from a payload; the
 * one endpoint that writes it validates against {@see PricingUnit} and
 * nothing else.
 *
 * `quantity_remaining` for a given (warehouse, variant) must always sum to that pair's
 * `WarehouseStock::quantity`. See `StockBatchLedgerTest`.
 */
#[UseFactory(StockBatchFactory::class)]
#[Fillable([])]
class StockBatch extends Model
{
    /** @use HasFactory<StockBatchFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => StockBatchSourceType::class,
            'unit_cost' => 'decimal:3',
            // What the press pays this layer's deal for a unit of it. Null on every layer the
            // company financed, and on a deal opened without the term.
            'printing_sale_price' => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'quantity_remaining' => 'decimal:3',
            'unit' => PricingUnit::class,
            'received_at' => 'datetime',
            // Null on every layer nobody has corrected, which is almost all of them. Set by
            // RevalueStockBatch so a list can say «سُعِّرت يدوياً» without joining the ledger.
            'revalued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The shelf this cost layer belongs to — see the note on {@see WarehouseStock::stockItem()}.
     *
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * The costed arrival line this layer traces back to. Null for an adjustment or opening-balance
     * layer, and — for now — also null for a plain purchase arrival; see the migration's docblock.
     *
     * @return BelongsTo<StockArrivalItem, $this>
     */
    public function stockArrivalItem(): BelongsTo
    {
        return $this->belongsTo(StockArrivalItem::class);
    }

    /**
     * The movement that brought this stock in.
     *
     * **The traceability `stock_arrival_item_id` above was reserved for and never got.** Through
     * it: who recorded it, which document (`stock_movements.reference_id`), and therefore which
     * purchase order. Null for every layer opened before the column existed — and honestly so,
     * since matching those by timestamp would be a guess.
     *
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /**
     * The layer this one was split off, when part of a batch was repriced.
     *
     * Points *from* the untouched remainder *to* the row that kept the FIFO position and took
     * the new cost — see {@see RevalueStockBatch} for why that
     * direction is the useful one.
     *
     * @return BelongsTo<StockBatch, $this>
     */
    public function splitFrom(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'split_from_batch_id');
    }

    /**
     * Every draw ever made against this layer.
     *
     * @return HasMany<StockBatchConsumption, $this>
     */
    public function consumptions(): HasMany
    {
        return $this->hasMany(StockBatchConsumption::class);
    }

    /**
     * Every time somebody changed what this layer is carried at, oldest first — a correction to
     * a correction reads as the story it is.
     *
     * @return HasMany<StockBatchRevaluation, $this>
     */
    public function revaluations(): HasMany
    {
        return $this->hasMany(StockBatchRevaluation::class)->orderBy('id');
    }

    /**
     * How much of this layer has already been drawn off.
     *
     * `received − remaining` rather than a sum of the consumption rows: a split moves both
     * figures together precisely so this subtraction stays true of each row on its own. It
     * understates after a cancelled order credits stock back, which is the same thing every
     * reversal in this schema does to a running figure and not something the split introduced.
     */
    public function consumedQuantity(): string
    {
        return bcsub((string) $this->quantity_received, (string) $this->quantity_remaining, 3);
    }

    /** Whether anything has been drawn off this layer yet — the line a revaluation warns on. */
    public function isPartlyConsumed(): bool
    {
        return bccomp($this->consumedQuantity(), '0', 3) > 0
            && bccomp((string) $this->quantity_remaining, '0', 3) > 0;
    }

    /** Whether there is anything left to reprice. See {@see BatchIsFullyConsumed}. */
    public function isFullyConsumed(): bool
    {
        return bccomp((string) $this->quantity_remaining, '0', 3) <= 0;
    }

    /** A layer nobody has priced — the reason `GET /stock-batches?uncosted=1` exists. */
    public function isUncosted(): bool
    {
        return bccomp((string) $this->unit_cost, '0', 3) <= 0;
    }
}
