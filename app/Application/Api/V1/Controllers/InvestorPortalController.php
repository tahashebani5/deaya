<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Resources\InvestorPortfolioResource;
use App\Application\Api\V1\Resources\InvestorWalletEntryResource;
use App\Application\Controller;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Exceptions\InvestorHasNoAccount;
use App\Domain\Investor\InvestorService;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Investor portal
 *
 * What an investor sees, and the only thing he can reach.
 *
 * **There is no id in any path here, and that is the security.** This application has no policy
 * classes; `can:` authorises an ability with no model, and an administrator is granted every
 * ability unconditionally — so «this investor sees only his own rows» cannot be expressed as a
 * permission. It is enforced by there being no id to tamper with: the investor is resolved from
 * the signed-in user's own `investors.user_id` link, and a user with no such link gets a 404
 * rather than somebody else's money.
 */
class InvestorPortalController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly InvestorService $investors) {}

    /**
     * My money
     *
     * Capital in the wallet, capital in deals, profit earned so far, and profit released and
     * withdrawable. With a line per deal.
     */
    public function summary(Request $request): JsonResponse
    {
        $investor = $this->investors->investorFor($request->user());

        if ($investor === null) {
            throw InvestorHasNoAccount::make();
        }

        return $this->success(new InvestorPortfolioResource($this->portfolio((int) $investor->getKey(), $investor)));
    }

    /**
     * My statement
     *
     * Every movement of my own money — where each dinar came from and, when something was taken
     * back, which order or expense took it.
     */
    public function statement(Request $request): JsonResponse
    {
        $investor = $this->investors->investorFor($request->user());

        if ($investor === null) {
            throw InvestorHasNoAccount::make();
        }

        $perPage = min(max((int) $request->integer('per_page', 25), 1), 100);

        $entries = InvestorWalletEntry::query()
            ->with(['deal', 'reversedEntry'])
            ->where('investor_id', $investor->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->successWithPagination(InvestorWalletEntryResource::collection($entries));
    }

    /**
     * @return array<string, mixed>
     */
    private function portfolio(int $investorId, $investor): array
    {
        $balances = $this->investors->balancesFor($investorId);

        $capitalInDeals = '0.00';
        $profitInDeals = '0.00';
        $deals = [];

        $rows = $investor->shares()->with('deal')->get();

        foreach ($rows as $share) {
            $dealId = (int) $share->investor_deal_id;
            $pots = $balances['deals'][$dealId] ?? ['capital' => '0.00', 'profit' => '0.00'];

            $capitalInDeals = bcadd($capitalInDeals, $pots['capital'], 2);
            $profitInDeals = bcadd($profitInDeals, $pots['profit'], 2);

            $deals[] = [
                'id' => $dealId,
                'code' => $share->deal?->code,
                'name' => $share->deal?->name,
                'status' => $share->deal?->status->value,
                'status_label' => $share->deal?->status->label(),
                'share_percent' => (string) $share->share_percent,
                'capital' => $pots['capital'],
                'profit' => $pots['profit'],
            ];
        }

        $withdrawn = (string) InvestorWalletEntry::query()
            ->where('investor_id', $investorId)
            ->where('type', WalletEntryType::ProfitWithdrawal->value)
            ->whereDoesntHave('reversedBy')
            ->sum('amount');

        return [
            'investor' => [
                'id' => $investorId,
                'code' => $investor->code,
                'name' => $investor->name,
            ],
            'capital_in_wallet' => $balances['wallet']['capital'],
            'capital_in_deals' => $capitalInDeals,
            'capital_total' => bcadd($balances['wallet']['capital'], $capitalInDeals, 2),
            'profit_in_deals' => $profitInDeals,
            'profit_available' => $balances['wallet']['profit'],
            'profit_withdrawn' => number_format((float) $withdrawn, 2, '.', ''),
            'deals' => $deals,
        ];
    }
}
