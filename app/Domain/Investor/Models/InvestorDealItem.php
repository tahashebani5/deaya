<?php

declare(strict_types=1);

namespace App\Domain\Investor\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Inventory\Models\StockItem;
use Database\Factories\InvestorDealItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One shelf a deal funds, with what was expected of it.
 *
 * **Never used to find the deal's stock.** That is always the cost layers carrying the deal's
 * id, because a transfer moves a layer to another warehouse and a revaluation splits it into a
 * new row — a list of shelves would go stale on the first of either. This row's jobs are three:
 * to validate a funding claim, to price the expected line, and to draw the screen.
 */
#[UseFactory(InvestorDealItemFactory::class)]
#[Fillable(['quantity_expected', 'expected_unit_cost', 'expected_unit_price', 'notes'])]
class InvestorDealItem extends Model
{
    /** @use HasFactory<InvestorDealItemFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_expected' => 'decimal:3',
            'expected_unit_cost' => 'decimal:3',
            'expected_unit_price' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<InvestorDeal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(InvestorDeal::class, 'investor_deal_id');
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
