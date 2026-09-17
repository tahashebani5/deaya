<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Investor\DTOs\InvestorDealData;
use App\Domain\Investor\Enums\DealStatus;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Settings\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * Opens the paperwork: the deal, the shelves it funds, and who is in it.
 *
 * The share of profit that goes to the investors is copied from the company default at this
 * moment and never read from there again. That is what makes the default safe to edit: moving it
 * tomorrow changes what the *next* deal is born with and cannot disturb a figure anybody has
 * already been paid against.
 */
final class CreateInvestorDeal
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SyncDealItems $syncItems,
        private readonly SyncDealShares $syncShares,
    ) {}

    public function __invoke(InvestorDealData $data, ?int $actorId): InvestorDeal
    {
        return DB::transaction(function () use ($data, $actorId): InvestorDeal {
            $deal = new InvestorDeal([
                'opened_on' => $data->openedOn,
                'notes' => $data->notes,
            ]);

            $deal->product_id = $data->productId;
            $deal->status = DealStatus::Draft;
            $deal->investor_profit_share_percent = $data->investorProfitSharePercent
                ?? $this->settings->investorProfitSharePercent();
            $deal->created_by = $actorId;
            $deal->save();

            ($this->syncItems)($deal, $data->items);
            ($this->syncShares)($deal, $data->shares);

            return $deal->load(['items.stockItem', 'shares.investor', 'product']);
        });
    }
}
