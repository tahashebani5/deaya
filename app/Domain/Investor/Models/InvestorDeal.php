<?php

declare(strict_types=1);

namespace App\Domain\Investor\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Investor\Actions\FundPurchaseOrder;
use App\Domain\Investor\Enums\DealStatus;
use App\Domain\Investor\Support\Money;
use Database\Factories\InvestorDealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * صفقة — one financed purchase of stock.
 *
 * **It holds no money and no quantity.** Everything a screen shows about it is derived: the
 * stock from the cost layers that carry its id, the money from `investor_wallet_entries`. A
 * cached profit column could only ever disagree with the ledger, and the ledger is the truth.
 *
 * `investor_profit_share_percent` is the investors' half of *this* deal, seeded from the company
 * default and frozen the moment the deal opens. Renegotiating a live deal is a new deal.
 *
 * `investor_funded_percent` is the fraction of the goods their money actually bought, and
 * `company_stake` the dinars it did not — «الباقي على الشركة». Both are written once by
 * {@see FundPurchaseOrder} and frozen with the rest; a deal built by
 * hand keeps the defaults, 100 and 0, and behaves as every deal did before them.
 *
 * `printing_sale_price` is **سعر السادة**, and it puts a deal on one of two roads for its whole
 * life:
 *
 * - **Set** — the press buys this deal's plain stock off the shelf the moment a printed line
 *   takes it, at this price by weight. The margin is settled there and then, split by the one
 *   {@see investorsCutOf()} both roads share, and nothing that happens to the order afterwards
 *   reaches the investor: not the customer's price, not the press's wages, not a cancellation.
 * - **Null** — the road every deal walked before 2026-09-06: the investors ride the sale itself,
 *   and are paid a share of the delivered order's profit ({@see investorsCutOf()}).
 *
 * The owner's framing: «الشركة نفسها مطبعة — كأننا بنشروه من المستثمر… استلم الزبون ما استلمش،
 * المطبعة تتحمّل». A deal never has both: a draw is priced or it is not, and the two mechanisms
 * are kept apart by `order_items.stock_purchased_at`.
 */
#[UseFactory(InvestorDealFactory::class)]
#[Fillable(['opened_on', 'notes'])]
class InvestorDeal extends Model implements HasAuditTrail
{
    /** @use HasFactory<InvestorDealFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DealStatus::class,
            'investor_profit_share_percent' => 'decimal:2',
            'company_stake' => 'decimal:2',
            'investor_funded_percent' => 'decimal:4',
            // سعر السادة — what the press pays this deal for a unit of its plain stock. Null on
            // every deal that predates the term, and on one funded without it.
            'printing_sale_price' => 'decimal:3',
            'opened_on' => 'date',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** «D25» — reserved before the insert, exactly as an order's number is. */
    protected static function booted(): void
    {
        static::creating(function (self $deal): void {
            if ($deal->code === null) {
                $id = (int) DB::scalar(
                    "select nextval(pg_get_serial_sequence('investor_deals', 'id'))"
                );

                $deal->id = $id;
                $deal->code = 'D'.$id;
            }
        });
    }

    /**
     * A label for the screen. **Never used to find this deal's stock** — that is always the cost
     * layers carrying its id, because a product does not own stock here and one shelf can stand
     * behind several products.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The shelves this deal funds.
     *
     * @return HasMany<InvestorDealItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvestorDealItem::class);
    }

    /**
     * Who is in it, and for what percentage.
     *
     * @return HasMany<InvestorDealShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(InvestorDealShare::class)->orderBy('id');
    }

    /**
     * @return HasMany<InvestorDealSupply, $this>
     */
    public function supplies(): HasMany
    {
        return $this->hasMany(InvestorDealSupply::class);
    }

    /**
     * @return HasMany<InvestorDealExpense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(InvestorDealExpense::class)->orderBy('incurred_on')->orderBy('id');
    }

    /**
     * @return HasMany<InvestorWalletEntry, $this>
     */
    public function walletEntries(): HasMany
    {
        return $this->hasMany(InvestorWalletEntry::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** Whether the terms may still be rewritten. */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    /**
     * Whether this deal is one purchase order's paperwork — and so has its ownership frozen.
     *
     * Such a deal fixed what fraction of the goods the partners bought when it was funded, so it
     * takes no more capital and its order takes no more edits. A deal assembled by hand has no
     * such basis and keeps behaving as before.
     */
    public function isBornFromPurchaseOrder(): bool
    {
        return $this->purchase_order_id !== null;
    }

    /**
     * The investors' cut of a figure earned or lost on this deal's goods.
     *
     * Two factors, both frozen when the deal opened — the fraction of the goods their money
     * bought, and the share of that fraction's result they keep:
     *
     * ```
     * cut = amount × investor_funded_percent ÷ 100 × investor_profit_share_percent ÷ 100
     * ```
     *
     * **One definition, called from everywhere a dinar reaches a wallet** — the order's profit,
     * its loss, an expense typed on the deal, and since 2026-09-11 the margin سعر السادة makes at
     * the shelf too — because two of them restating it is how a 1,000 customs invoice comes to
     * cost partners who own 750 of the profit 500 of it.
     *
     * **The shelf margin used to be split by ownership alone**, on the reading that a purchase at
     * an agreed price pays for no work and so owes the company no half. The owner settled it the
     * other way on 2026-09-11 — «نعم على اغلب حتى هو بيتوزع 5/5» — so the two roads differ now in
     * *when* a deal is paid and *what* it is paid on, never in how the result is divided.
     *
     * The sign travels with the amount; the magnitude is rounded once at the end, and a result
     * that rounds to nothing is returned as plain zero rather than «-0.00».
     */
    public function investorsCutOf(string $amount): string
    {
        $negative = bccomp($amount, '0', Money::SCALE) < 0;
        $magnitude = $negative ? substr($amount, 1) : $amount;

        $cut = Money::round(bcdiv(
            bcmul(
                bcmul($magnitude, (string) $this->investor_funded_percent, 8),
                (string) $this->investor_profit_share_percent,
                8,
            ),
            '10000',
            8,
        ));

        return $negative && bccomp($cut, '0', Money::SCALE) !== 0 ? '-'.$cut : $cut;
    }

    /** Whether this deal sells its plain stock to the press at an agreed price. */
    public function sellsToThePress(): bool
    {
        return $this->printing_sale_price !== null;
    }

    /**
     * Whether any cost layer still carries this deal with stock left on it.
     *
     * Asked before closing, and read straight off `stock_batches` rather than off a column, for
     * the reason the whole model rests on: the layers are where the truth is.
     */
    public function stillHoldsStock(): bool
    {
        return DB::table('stock_batches')
            ->where('investor_deal_id', $this->getKey())
            ->whereNull('deleted_at')
            ->where('quantity_remaining', '>', 0)
            ->exists();
    }
}
