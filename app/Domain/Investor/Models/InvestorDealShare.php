<?php

declare(strict_types=1);

namespace App\Domain\Investor\Models;

use App\Domain\Audit\Concerns\Auditable;
use Database\Factories\InvestorDealShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One investor's stake in one deal.
 *
 * `share_percent` is his slice **of the investors' half** — of the deal's
 * `investor_profit_share_percent` — so the owner's «50% للمستثمرين» and «أحمد 60%» are two
 * multiplications rather than one number doing both jobs.
 *
 * `committed_amount` is the **pledge**: the figure the percentage was agreed against. What he
 * actually has in the deal is `Σ allocation − Σ release` on his wallet ledger, and the two are
 * shown side by side and never quietly reconciled — a man who pledged 30,000 and handed over
 * 25,000 should see both numbers, not an average of them. **Nothing in any profit or capital
 * computation reads this column.**
 *
 * A model rather than a pivot, because a pivot fires no events and keeps no history, and «من
 * غيّر نسبة أحمد؟» is exactly the question this table will be asked.
 */
#[UseFactory(InvestorDealShareFactory::class)]
#[Fillable(['committed_amount', 'share_percent', 'notes'])]
class InvestorDealShare extends Model
{
    /** @use HasFactory<InvestorDealShareFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'committed_amount' => 'decimal:2',
            'share_percent' => 'decimal:4',
            'joined_at' => 'datetime',
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
     * @return BelongsTo<Investor, $this>
     */
    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }
}
