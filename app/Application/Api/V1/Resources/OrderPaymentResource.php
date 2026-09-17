<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Order\Models\OrderPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderPayment
 */
class OrderPaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,

            'type' => $this->type->value,
            'type_label' => $this->type->label(),

            // Always positive. Which direction it moves is the type's business — see the model.
            // A string, like every other money field: what was stored reaches the client exactly.
            'amount' => (string) $this->amount,

            // Null on a reversal alone, because no money moved for it to have a method.
            'method' => $this->method?->value,
            'method_label' => $this->method?->label(),

            'reference' => $this->reference,

            // The receipt (الواصل) — always present on a transfer, because nothing else proves
            // one happened. The URL is built per request rather than stored: the disk is private,
            // so in production this is a signed link that expires, and a permanent one sitting in
            // a JSON payload would be somebody's bank details left on the table.
            'has_receipt' => $this->hasReceipt(),
            // Whether it is a picture the app can draw full screen, or a PDF it hands to the
            // phone. Decided here from the stored file, so the app holds no format list.
            'receipt_is_image' => $this->receiptIsImage(),
            'receipt_url' => $this->receiptUrl(),
            'receipt_filename' => $this->receipt_original_filename,
            'receipt_size_bytes' => $this->receipt_size_bytes,

            // When the money moved. `created_at` beside it is when somebody typed it in, and the
            // two genuinely differ on a back-dated deposit — both are published because a cash
            // report needs the first and an audit needs the second.
            'paid_at' => $this->paid_at?->toIso8601String(),

            'notes' => $this->notes,

            // **The two flags the app draws its ledger from**, so no copy of the rules lives in
            // Dart. `is_reversed` strikes the row through; `is_reversible` is what puts a cancel
            // action on it — and the server has already decided that a refund and a reversal
            // are not candidates.
            'is_reversed' => $this->isReversed(),
            'is_reversible' => $this->isReversible(),

            // Which entry this one undoes, on a reversal.
            'reverses_payment_id' => $this->reverses_payment_id,

            // And the other way round, so a struck-through row can name its correction without
            // the client pairing rows up by eye.
            'reversal' => $this->whenLoaded('reversal', fn (): ?array => $this->reversal === null ? null : [
                'id' => $this->reversal->id,
                'reason' => $this->reversal->notes,
                'created_at' => $this->reversal->created_at?->toIso8601String(),
            ]),

            'recorded_by' => $this->recorded_by,
            'recorder' => $this->whenLoaded('recorder', fn (): ?array => $this->recorder === null ? null : [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
                'employee_code' => $this->recorder->employee_code,
            ]),

            // No `updated_at`: a ledger entry is never updated, and publishing one would invite
            // a client to believe it could be.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
