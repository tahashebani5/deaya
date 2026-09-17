<?php

declare(strict_types=1);

namespace App\Domain\Investor\Queries;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealSupply;

/**
 * «من موّل هذا الأمر؟» — answered on the purchase order's own screen.
 *
 * Read by the purchase-order screen rather than the deal's, because that is where the question
 * is asked: a person looking at a lorry wants to know whose money is on it before he receives
 * it, and until now the only way to find out was to go looking through the deals.
 *
 * Each deal comes back with the lines it claims and the partners in it — the amount each put in
 * beside the percentage that amount produced, so the two are read together and neither has to be
 * taken on trust.
 */
final class PurchaseOrderFundingQuery
{
    /**
     * @return list<array{
     *     deal_id: int,
     *     code: string,
     *     status: string,
     *     status_label: string,
     *     investor_profit_share_percent: string,
     *     stock_item_ids: list<int>,
     *     investors: list<array{investor_id: int, name: string, committed_amount: string, share_percent: string}>,
     * }>
     */
    public function __invoke(int $purchaseOrderId): array
    {
        $claims = InvestorDealSupply::query()
            ->where('source_type', AuditSubject::PurchaseOrder->value)
            ->where('source_id', $purchaseOrderId)
            ->get(['investor_deal_id', 'stock_item_id']);

        if ($claims->isEmpty()) {
            return [];
        }

        $deals = InvestorDeal::query()
            ->whereIn('id', $claims->pluck('investor_deal_id')->unique()->all())
            ->with('shares.investor')
            ->orderBy('id')
            ->get();

        return $deals->map(fn (InvestorDeal $deal): array => [
            'deal_id' => (int) $deal->getKey(),
            'code' => (string) $deal->code,
            'status' => $deal->status->value,
            'status_label' => $deal->status->label(),
            'investor_profit_share_percent' => (string) $deal->investor_profit_share_percent,
            'stock_item_ids' => $claims
                ->where('investor_deal_id', $deal->getKey())
                ->pluck('stock_item_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'investors' => $deal->shares->map(fn ($share): array => [
                'investor_id' => (int) $share->investor_id,
                'name' => (string) ($share->investor?->name ?? ''),
                // The money he put in, and the percentage it produced. Written together by
                // `FundPurchaseOrder`, and shown together so neither is taken on trust.
                'committed_amount' => (string) $share->committed_amount,
                'share_percent' => (string) $share->share_percent,
            ])->values()->all(),
        ])->values()->all();
    }
}
