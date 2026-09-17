<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\Vendor\Actions\ReverseStockArrival;
use App\Domain\Vendor\Models\StockArrival;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'vendor_id' => $this->vendor_id,
            'vendor' => $this->whenLoaded('vendor', fn (): array => [
                'id' => $this->vendor->id,
                'name' => $this->vendor->name,
            ]),

            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded(
                'warehouse',
                fn (): ?array => $this->warehouse === null ? null : [
                    'id' => $this->warehouse->id,
                    'name' => $this->warehouse->name,
                ],
            ),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'order_date' => $this->order_date?->toDateString(),
            'expected_date' => $this->expected_date?->toDateString(),
            'notes' => $this->notes,

            // Null on an order raised before cost tracking existed — see RecalculatePurchaseOrderTotal.
            // Already inclusive of total_additional_cost: each line's final_total_cost (summed
            // into this figure) already carries its allocated share.
            'total_amount' => $this->total_amount !== null ? (string) $this->total_amount : null,
            'total_additional_cost' => $this->total_additional_cost !== null ? (string) $this->total_additional_cost : null,

            // The deals financing this order, each with the lines it claims and the partners in
            // it — the money each put in beside the percentage it produced. Present on the single
            // order only, and an empty list on the ordinary one the company paid for itself.
            // `isset` rather than a read: strict mode throws on an attribute that was never
            // set, and this one is attached by the show endpoint alone — the list never carries
            // it. Same shape as `InvestorDealResource::$balances`.
            'investor_funding' => $this->when(
                isset($this->investor_funding),
                fn (): array => $this->investor_funding,
            ),

            // The share a deal struck on this order would be born with — the company default,
            // published so the funding screen can show it rather than imply it.
            'default_investor_profit_share_percent' => $this->when(
                isset($this->default_investor_profit_share_percent),
                fn (): string => $this->default_investor_profit_share_percent,
            ),

            // **When the receipt on this order stops being undoable, and whether this caller may
            // still undo it.** Published so the screen can show or hide «تراجع عن الاستلام»
            // rather than let somebody discover the answer by getting a 422 — and computed
            // against the *asking* user's grants, so the manager who may step past the window
            // sees the button on the third day and the storekeeper does not.
            //
            // Both null/false on every order with no live receipt behind it, which is every
            // order that is not `completed`. Only present where `stockArrivals` was loaded: the
            // list does not carry it, the same rule `investor_funding` above follows.
            'receipt_reversible_until' => $this->whenLoaded(
                'stockArrivals',
                fn (): ?string => $this->reversalDeadline(),
            ),

            'can_reverse_receipt' => $this->whenLoaded(
                'stockArrivals',
                fn (): bool => $this->canReverseReceipt($request),
            ),

            'items' => PurchaseOrderItemResource::collection($this->whenLoaded('items')),
            'additional_costs' => PurchaseOrderAdditionalCostResource::collection($this->whenLoaded('additionalCosts')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The receipt on this order that has not already been taken back, if there is one.
     *
     * The same question `ReversePurchaseOrderReceipt` asks, read off the loaded relation rather
     * than re-queried — this runs once per row of a list in the worst case.
     */
    private function reversibleArrival(): ?StockArrival
    {
        if ($this->status !== PurchaseOrderStatus::Completed) {
            return null;
        }

        return $this->stockArrivals
            ->reject(fn (StockArrival $arrival): bool => $arrival->isReversed())
            ->sortByDesc('id')
            ->first();
    }

    /** When the window on this order's live receipt closes, or null if it has none. */
    private function reversalDeadline(): ?string
    {
        $arrival = $this->reversibleArrival();

        return $arrival === null
            ? null
            : ReverseStockArrival::windowClosesAt($arrival)->toIso8601String();
    }

    /**
     * Whether *this* caller may undo it right now — the window, or the grant that waives it.
     *
     * Deliberately does not attempt the three guards a reversal also has to pass (nothing drawn
     * from the layers, nothing repriced, the receipt not already undone): answering those means
     * reading the cost layers behind every line, which is a query per order in a list to
     * pre-empt a refusal the domain gives clearly. The button being offered and the act being
     * refused is the honest failure here; the button being hidden on a receipt that is perfectly
     * reversible would not be.
     */
    private function canReverseReceipt(Request $request): bool
    {
        $arrival = $this->reversibleArrival();

        if ($arrival === null) {
            return false;
        }

        if (ReverseStockArrival::isWithinWindow($arrival)) {
            return true;
        }

        return $request->user()?->can(PermissionName::ReverseReceiptAnyTime->value) ?? false;
    }
}
