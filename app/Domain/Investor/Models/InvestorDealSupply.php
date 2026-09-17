<?php

declare(strict_types=1);

namespace App\Domain\Investor\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use Database\Factories\InvestorDealSupplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * «this purchase order's line for this shelf is financed by this deal» — declared in advance.
 *
 * It is the whole answer to «الموظف لا يختار الصفقة أبداً»: the decision is made by whoever runs
 * the deals, before the lorry arrives, and the receiving screen never grows a field. At receipt
 * `ReceivePurchaseOrder` asks Investment one question per line and stamps whatever comes back
 * onto the cost layer it opens.
 */
#[UseFactory(InvestorDealSupplyFactory::class)]
#[Fillable([])]
class InvestorDealSupply extends Model
{
    /** @use HasFactory<InvestorDealSupplyFactory> */
    use Auditable, HasFactory, SoftDeletes;

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

    /**
     * @return BelongsTo<User, $this>
     */
    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }
}
