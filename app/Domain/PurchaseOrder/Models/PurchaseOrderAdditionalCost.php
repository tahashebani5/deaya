<?php

declare(strict_types=1);

namespace App\Domain\PurchaseOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use Database\Factories\PurchaseOrderAdditionalCostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One order-level cost that isn't tied to any single line — delivery, unloading, customs.
 *
 * No `/logs` route of its own — like {@see PurchaseOrderItem}, its history is read through the
 * document that owns it, via `PurchaseOrder::auditTrailSubjects()`.
 *
 * `amount` is what it costs, in full; there is no per-unit figure here — a delivery fee has no
 * unit to divide by. Its effect on a line's cost happens the other way around, via
 * {@see AllocatePurchaseOrderAdditionalCosts}, which spreads the sum of every row here across
 * the order's own lines.
 */
#[UseFactory(PurchaseOrderAdditionalCostFactory::class)]
#[Fillable(['name', 'amount'])]
class PurchaseOrderAdditionalCost extends Model
{
    /** @use HasFactory<PurchaseOrderAdditionalCostFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
