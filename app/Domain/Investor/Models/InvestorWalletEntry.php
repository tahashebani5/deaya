<?php

declare(strict_types=1);

namespace App\Domain\Investor\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Domain\Investor\Enums\WalletEntryType;
use Database\Factories\InvestorWalletEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One movement of one investor's money.
 *
 * The amount is **always positive**; {@see WalletEntryType} says what the row means and
 * {@see signedAmount()} is the only place a sign is ever applied. Nothing is edited or deleted:
 * a mistake is a `reversal` row carrying the original amount verbatim, which is how
 * `order_payments` has always worked here.
 *
 * **A reversal answers for the row it undoes.** `affectsCapital()` and the two beside it read
 * through `reversedEntry`, so reversing a capital return can never credit a profit payout — the
 * precise trap `OrderPayment::affectsWriteOff()` exists to prevent, and the reason those
 * predicates live on the model rather than on the enum.
 */
#[UseFactory(InvestorWalletEntryFactory::class)]
#[Fillable(['amount', 'method', 'reference', 'occurred_at', 'notes'])]
class InvestorWalletEntry extends Model
{
    /** @use HasFactory<InvestorWalletEntryFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WalletEntryType::class,
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Investor, $this>
     */
    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
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
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /**
     * The row that undid this one, if any.
     *
     * `HasOne` because the partial unique index behind `reverses_entry_id` makes a second one
     * impossible. It is the useful direction for a report — «every row that still stands» is
     * `whereDoesntHave('reversedBy')`, with no flag on the original for anybody to forget.
     *
     * @return HasOne<self, $this>
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** The type this row acts on — its own, or that of the row it reverses. */
    public function effectiveType(): WalletEntryType
    {
        return $this->type === WalletEntryType::Reversal
            ? ($this->reversedEntry?->type ?? WalletEntryType::Reversal)
            : $this->type;
    }

    /**
     * What this row does to each of the four balances, as signed decimal strings.
     *
     * **The single definition every figure in the feature is built from** — the wallet, the deal
     * screen, every ceiling before a withdrawal, and each line of a statement. Three types move
     * two balances at once, which is why there is no `pot` column on the row: it would have to
     * name one of the two and the row would vanish from the statement of the other.
     *
     * A reversal is the negation of the row it undoes, which is the whole of undoing. It reads
     * that through `reversedEntry`, so reversing a capital return can never credit a profit
     * payout — the trap `OrderPayment::affectsWriteOff()` exists to prevent.
     *
     * @return array{capital_wallet: string, capital_deal: string, profit_deal: string, profit_wallet: string}
     */
    public function deltas(): array
    {
        $amount = (string) $this->amount;
        $isReversal = $this->type === WalletEntryType::Reversal;
        $multipliers = $this->effectiveType()->deltas();

        $deltas = [];

        foreach ($multipliers as $bucket => $multiplier) {
            $multiplier = $isReversal ? -$multiplier : $multiplier;

            $deltas[$bucket] = $multiplier === 0
                ? '0.00'
                : bcmul($amount, (string) $multiplier, 2);
        }

        return $deltas;
    }

    /** The one figure a statement line shows, from whichever balance this row actually moved. */
    public function signedAmount(): string
    {
        foreach ($this->deltas() as $delta) {
            if (bccomp($delta, '0', 2) !== 0) {
                return $delta;
            }
        }

        return '0.00';
    }

    /** Whether it has already been undone. */
    public function isReversed(): bool
    {
        return self::query()->where('reverses_entry_id', $this->getKey())->exists();
    }

    /**
     * Whether a person may reverse it.
     *
     * A reversal is not itself reversible — «عكسُ عكسٍ متاهةٌ بلا أرضية», the same line
     * `OrderPayment::isReversible()` draws. And an earning is not reversed by hand: it is undone
     * by the order that produced it changing, so that the ledger and the order can never say two
     * different things.
     */
    public function isReversible(): bool
    {
        return $this->type->isRecordableByHand() && ! $this->isReversed();
    }
}
