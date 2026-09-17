<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Investor\Models\InvestorDeal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvestorDeal
 */
class InvestorDealResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'can_be_edited' => $this->status->isEditable(),

            // The investors' share of THIS deal's profit — copied from the company default when
            // the deal was created and frozen when it opened. Published so a screen never has to
            // fetch the setting to explain a number.
            'investor_profit_share_percent' => (string) $this->investor_profit_share_percent,

            // The company as a partner: what it put in beside the investors — «الباقي على
            // الشركة» — and the fraction of the goods the investors' money bought. Both derived
            // at funding and frozen; 0 and 100 on a deal assembled by hand, which owns all of its
            // goods as every deal once did.
            'company_stake' => (string) $this->company_stake,
            'investor_funded_percent' => (string) $this->investor_funded_percent,
            // سعر السادة — what the press pays this deal for a unit of its plain stock, agreed
            // while the lorry was being funded. Null puts the deal on the other road entirely:
            // its investors are paid out of the delivered order's profit instead.
            'printing_sale_price' => $this->printing_sale_price === null
                ? null
                : (string) $this->printing_sale_price,

            // The order this deal was born from, when it was. A deal assembled by hand out of
            // several orders has none, and that is a real deal too — hence nullable rather than
            // a promise the screen can lean on.
            'purchase_order_id' => $this->purchase_order_id,

            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn (): ?array => $this->product === null ? null : [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),

            'opened_on' => $this->opened_on?->toDateString(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'notes' => $this->notes,

            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'stock_item_id' => $item->stock_item_id,
                'stock_item' => $item->relationLoaded('stockItem') && $item->stockItem !== null ? [
                    'id' => $item->stockItem->id,
                    'code' => $item->stockItem->code,
                    'display_name' => $item->stockItem->displayName(),
                ] : null,
                'quantity_expected' => $item->quantity_expected === null ? null : (string) $item->quantity_expected,
                'expected_unit_cost' => $item->expected_unit_cost === null ? null : (string) $item->expected_unit_cost,
                'expected_unit_price' => $item->expected_unit_price === null ? null : (string) $item->expected_unit_price,
            ])->all()),

            'investors' => $this->whenLoaded('shares', fn (): array => $this->shares->map(fn ($share): array => [
                'id' => $share->id,
                'investor_id' => $share->investor_id,
                'investor' => $share->relationLoaded('investor') && $share->investor !== null ? [
                    'id' => $share->investor->id,
                    'code' => $share->investor->code,
                    'name' => $share->investor->name,
                ] : null,
                // The pledge, which may legitimately differ from what arrived. The money itself
                // is in `balances` below, and the two are never quietly reconciled.
                'committed_amount' => (string) $share->committed_amount,
                'share_percent' => (string) $share->share_percent,
                'joined_at' => $share->joined_at?->toIso8601String(),
            ])->all()),

            // Capital held and profit earned, per investor and in total — walked from the ledger
            // on the detail screen only.
            // Per investor as a list naming its investor — see InvestorResource for why an
            // integer-keyed map is the wrong shape to put on a wire.
            'balances' => $this->when(isset($this->balances), fn (): array => [
                'capital' => $this->balances['capital'],
                'profit' => $this->balances['profit'],
                'per_investor' => array_map(
                    fn (int $investorId): array => [
                        'investor_id' => $investorId,
                        'capital' => $this->balances['per_investor'][$investorId]['capital'],
                        'profit' => $this->balances['per_investor'][$investorId]['profit'],
                    ],
                    array_keys($this->balances['per_investor']),
                ),
            ]),
            'stock' => $this->when(isset($this->stock), fn (): array => $this->stock),

            // What the deal's orders made it, delivered and on the road kept apart — the second
            // is a forecast, and adding it to the first without saying so would put money on the
            // screen that a single cancellation takes back. Detail screen only, like `balances`:
            // it walks every order the deal ever sold into.
            'orders_profit' => $this->when(isset($this->orders_profit), fn (): array => $this->orders_profit),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
