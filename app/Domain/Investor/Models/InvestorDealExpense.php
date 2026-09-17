<?php

declare(strict_types=1);

namespace App\Domain\Investor\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Investor\Enums\DealExpenseKind;
use Database\Factories\InvestorDealExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One cost booked against a deal.
 *
 * **`is_landed` decides whether it is subtracted, and only the server sets it.** Shipping and
 * customs typed on a purchase order are already inside the cost of the layers that arrived, so a
 * mirrored row is kept for the record and never subtracted a second time. A client that could
 * post the flag could pay an investor for one invoice twice.
 *
 * Append-only. A correction is a reversing row with the amount copied verbatim and a reason
 * required — the `ReverseOrderPayment` shape.
 */
#[UseFactory(InvestorDealExpenseFactory::class)]
#[Fillable(['kind', 'name', 'amount', 'incurred_on', 'notes'])]
class InvestorDealExpense extends Model
{
    /** @use HasFactory<InvestorDealExpenseFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DealExpenseKind::class,
            'amount' => 'decimal:2',
            'is_landed' => 'boolean',
            'incurred_on' => 'date',
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
     * @return BelongsTo<self, $this>
     */
    public function reversedExpense(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_expense_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isReversed(): bool
    {
        return self::query()->where('reverses_expense_id', $this->getKey())->exists();
    }

    /**
     * Whether this row reduces the deal's profit.
     *
     * A landed cost is logged and not subtracted; a reversal and anything already reversed drop
     * out. One predicate, so nine read sites cannot disagree about it.
     */
    public function isDeducted(): bool
    {
        return ! $this->is_landed
            && $this->reverses_expense_id === null
            && ! $this->isReversed();
    }
}
