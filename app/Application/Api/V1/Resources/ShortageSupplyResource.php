<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Shortage\Models\ShortageSupply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of §٩'s table: the quantity, the value, the method and the employee.
 *
 * @mixin ShortageSupply
 */
class ShortageSupplyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shortage_id' => $this->shortage_id,

            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),

            // Strings, like every quantity and every money field: what was stored reaches the
            // client exactly, without a float's opinion in the middle.
            'quantity' => (string) $this->quantity,

            // Null on a quantity that merely arrived from the order — nothing was bought, so
            // there is no price and no method. See SupplyKind.
            'amount' => $this->amount === null ? null : (string) $this->amount,
            'method' => $this->method?->value,
            'method_label' => $this->method?->label(),
            'reference' => $this->reference,

            // When the goods were got. `created_at` beside it is when somebody typed it in, and
            // the two genuinely differ on an entry made the following morning.
            'occurred_on' => $this->occurred_on?->toDateString(),

            // **Where the goods went, and the arrival that put them there.** Null together on a
            // purchase that moved no stock — see the migration's `arrival_shape` CHECK. Published
            // so a detail screen can say «دخلت المخزن» rather than leaving a reader to wonder
            // whether the sacks exist anywhere but in this row.
            'warehouse_id' => $this->warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn (): ?array => $this->warehouse === null ? null : [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ]),
            'stock_movement_id' => $this->stock_movement_id,
            'moved_stock' => $this->movedStock(),

            'notes' => $this->notes,

            // **الواصل, published exactly as a payment's is** — the app keeps no copy of the
            // format list, and a null url is «لا ورقة» rather than «لم يُسأل».
            'has_receipt' => $this->hasReceipt(),
            'receipt_is_image' => $this->receiptIsImage(),
            'receipt_url' => $this->receiptUrl(),
            'receipt_filename' => $this->receipt_original_filename,

            // **The two flags the app draws its ledger from**, so no copy of the rules lives in
            // Dart — the `OrderPaymentResource` arrangement. `is_reversed` strikes the row
            // through; `is_reversible` is what puts a cancel action on it, and the server has
            // already decided that an arrival and a reversal are not candidates.
            'is_reversal' => $this->isReversal(),
            'is_reversed' => $this->isReversed(),
            'is_reversible' => $this->kind->requiresPayment()
                && ! $this->isReversal()
                && ! $this->isReversed(),

            'reverses_supply_id' => $this->reverses_supply_id,

            'recorded_by_user_id' => $this->recorded_by_user_id,
            'recorder' => $this->whenLoaded('recorder', fn (): ?array => $this->recorder === null ? null : [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
                'employee_code' => $this->recorder->employee_code,
            ]),

            // No `updated_at`: a ledger entry is never updated, and publishing one would invite a
            // client to believe it could be.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
