<?php

declare(strict_types=1);

namespace App\Domain\Investor\Enums;

use App\Domain\Investor\Models\InvestorWalletEntry;

/**
 * Every way money moves for an investor — and the direction, which never lives in a sign.
 *
 * The amount on a row is always positive, exactly as in `order_payments`; this enum says what
 * the row *means*, and {@see InvestorWalletEntry::signedAmount()} is the one place a sign is
 * applied. Two pots (capital, profit) and two places (the wallet, a deal) give the whole model:
 *
 * ```
 * capital in wallet = deposit − withdrawal − allocation + release
 * capital in deal D = allocation(D) − release(D)
 * profit in deal D  = profit(D) − loss(D) − profitRelease(D)
 * profit in wallet  = profitRelease − profitWithdrawal
 * ```
 *
 * **Money can only leave from the wallet, and profit only reaches the wallet when a deal
 * closes.** That is not a rule written in an action — it is the only path the types allow, and
 * it is what makes «الربح يأتي تدريجياً ولا يُسحب إلا عند انتهاء الصفقة» impossible to get
 * wrong.
 */
enum WalletEntryType: string
{
    /** The investor handed money to the company. */
    case Deposit = 'deposit';

    /** He took capital back out of the wallet. */
    case Withdrawal = 'withdrawal';

    /** Wallet capital committed to a deal. */
    case Allocation = 'allocation';

    /** That capital coming back to the wallet when the deal closes. */
    case Release = 'release';

    /** A share of one order's profit, earned by a deal. */
    case Profit = 'profit';

    /** A share of one order's loss, borne by a deal. */
    case Loss = 'loss';

    /**
     * A losing deal's shortfall taken out of the capital the investor put **into that deal**.
     *
     * Every amount in this table is positive, so there is no negative release to hand back with.
     * A deal whose share of the losses came to −6,500 against 30,000 of capital returns 23,500,
     * and this row is the 6,500 — visible, named, and touching no other deal's money.
     */
    case CapitalWritedown = 'capital_writedown';

    /**
     * The part of a loss that exceeded what the investor had in the deal.
     *
     * Nothing in the arrangement makes him owe more than he put in, so the remainder is the
     * company's. Written as its own line so it appears on his statement instead of quietly
     * disappearing into a rounding difference.
     */
    case LossAbsorbedByCompany = 'loss_absorbed_by_company';

    /** The deal's net profit moving to the wallet at close — where it becomes withdrawable. */
    case ProfitRelease = 'profit_release';

    /** Profit paid out to the investor. */
    case ProfitWithdrawal = 'profit_withdrawal';

    /** Undoes one earlier row, carrying its amount verbatim. */
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'إيداع رأس مال',
            self::Withdrawal => 'سحب رأس مال',
            self::Allocation => 'تمويل صفقة',
            self::Release => 'إرجاع رأس المال من الصفقة',
            self::Profit => 'ربح من طلبية',
            self::Loss => 'خسارة من طلبية',
            self::CapitalWritedown => 'خصم خسارة من رأس المال',
            self::LossAbsorbedByCompany => 'خسارة تحمّلتها الشركة',
            self::ProfitRelease => 'إتاحة أرباح الصفقة للسحب',
            self::ProfitWithdrawal => 'سحب أرباح',
            self::Reversal => 'عكس حركة',
        };
    }

    /** Whether the row adds to whichever total it belongs to. */
    public function isCredit(): bool
    {
        return match ($this) {
            self::Deposit, self::Release, self::Profit, self::ProfitRelease => true,
            default => false,
        };
    }

    /**
     * How this row moves each of the four buckets, as multipliers on its amount.
     *
     * **One table, read by every balance, every ceiling and every statement row.** Three of the
     * types move two buckets at once — a writedown takes from capital in the deal and gives to
     * profit in it — which is exactly why a single `pot` column on the row would have been a
     * lie: it would have to name one of the two, and the row would then vanish from the
     * statement of whichever it did not name.
     *
     * @return array{capital_wallet: int, capital_deal: int, profit_deal: int, profit_wallet: int}
     */
    public function deltas(): array
    {
        return match ($this) {
            self::Deposit => ['capital_wallet' => 1, 'capital_deal' => 0, 'profit_deal' => 0, 'profit_wallet' => 0],
            self::Withdrawal => ['capital_wallet' => -1, 'capital_deal' => 0, 'profit_deal' => 0, 'profit_wallet' => 0],
            self::Allocation => ['capital_wallet' => -1, 'capital_deal' => 1, 'profit_deal' => 0, 'profit_wallet' => 0],
            self::Release => ['capital_wallet' => 1, 'capital_deal' => -1, 'profit_deal' => 0, 'profit_wallet' => 0],
            self::Profit => ['capital_wallet' => 0, 'capital_deal' => 0, 'profit_deal' => 1, 'profit_wallet' => 0],
            self::Loss => ['capital_wallet' => 0, 'capital_deal' => 0, 'profit_deal' => -1, 'profit_wallet' => 0],
            self::CapitalWritedown => ['capital_wallet' => 0, 'capital_deal' => -1, 'profit_deal' => 1, 'profit_wallet' => 0],
            self::LossAbsorbedByCompany => ['capital_wallet' => 0, 'capital_deal' => 0, 'profit_deal' => 1, 'profit_wallet' => 0],
            self::ProfitRelease => ['capital_wallet' => 0, 'capital_deal' => 0, 'profit_deal' => -1, 'profit_wallet' => 1],
            self::ProfitWithdrawal => ['capital_wallet' => 0, 'capital_deal' => 0, 'profit_deal' => 0, 'profit_wallet' => -1],
            // A reversal has no deltas of its own — it takes the negation of the row it undoes.
            self::Reversal => ['capital_wallet' => 0, 'capital_deal' => 0, 'profit_deal' => 0, 'profit_wallet' => 0],
        };
    }

    /** Whether real money crossed the counter — the rows that must name a method. */
    public function movedCash(): bool
    {
        return $this === self::Deposit
            || $this === self::Withdrawal
            || $this === self::ProfitWithdrawal;
    }

    /** Whether this row belongs to a deal rather than to the wallet. */
    public function belongsToADeal(): bool
    {
        return match ($this) {
            self::Allocation, self::Release, self::Profit, self::Loss,
            self::CapitalWritedown, self::LossAbsorbedByCompany, self::ProfitRelease => true,
            default => false,
        };
    }

    /**
     * Whether a person may record this by hand.
     *
     * `profit` and `loss` are written by the order flow, and `release`/`profit_release` by
     * closing a deal. Offering them on a form would be offering somebody the chance to invent
     * an earning.
     */
    public function isRecordableByHand(): bool
    {
        return match ($this) {
            self::Deposit, self::Withdrawal, self::Allocation, self::ProfitWithdrawal => true,
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function recordableValues(): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), fn (self $type) => $type->isRecordableByHand()),
        ));
    }
}
