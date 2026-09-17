<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Shortage\Models\Shortage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shortage as every screen reads it — §٧ of the brief, in one payload.
 *
 * **`remaining_quantity` is computed, never stored**, and it is published because the client must
 * not compute it either: a subtraction done in Dart is a second implementation of the rule that
 * decides when a shortage is finished, and the two would disagree the first time rounding came
 * into it.
 *
 * **`available_transitions` and the three `can_*` flags come from the server** for the same
 * reason `OrderResource` publishes its own: the state machine lives in one enum, and a client
 * that held its own copy would draw a «مكتمل» button that the API then refuses.
 *
 * @mixin Shortage
 */
class ShortageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,

            'source' => $this->source->value,
            'source_label' => $this->source->label(),

            'name' => $this->name,
            'unit' => $this->unit->value,
            'unit_label' => $this->unit->label(),

            // The three numbers §٧ asks for, and the fourth derived from them.
            'required_quantity' => (string) $this->required_quantity,
            'supplied_quantity' => (string) $this->supplied_quantity,
            'remaining_quantity' => $this->remainingQuantity(),
            'total_paid' => (string) $this->total_paid,

            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_final' => $this->status->isFinal(),

            // What a person may choose from here — «مكتمل» never among them, because the
            // arithmetic writes it. See ShortageStatus.
            'available_transitions' => array_map(
                fn ($status): array => ['value' => $status->value, 'label' => $status->label()],
                $this->status->allowedNext(),
            ),

            // Whether the section may still change what this shortage is *about*. False on an
            // order-born one, where the line is the authority — so the app greys the form rather
            // than offering an edit the API will refuse.
            'is_editable' => $this->isEditable(),

            // **Whether recording a supply will ask for a warehouse.** The app draws or omits the
            // picker from this rather than inferring it from `product_variant_id` — the rule is
            // the server's, and a client copy would ask for a shelf for a roll of tape.
            'is_stockable' => $this->isStockable(),

            'order_id' => $this->order_id,
            'order_item_id' => $this->order_item_id,
            'order' => $this->whenLoaded('order', fn (): ?array => $this->order === null ? null : [
                'id' => $this->order->id,
                'code' => $this->order->code,
                'status' => $this->order->status->value,
                // **Published deliberately.** A reader who can see this row has already been
                // allowed to — the list filters archived orders out and the detail route charges
                // the archive grant — so hiding the flag here would only stop the app saying why
                // an order's link leads somewhere unusual.
                'is_archived' => $this->order->trashed(),
            ]),

            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn (): ?array => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),

            'product_id' => $this->product_id,
            'product_variant_id' => $this->product_variant_id,
            'product' => $this->whenLoaded('product', fn (): ?array => $this->product === null ? null : [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            'variant' => $this->whenLoaded('productVariant', fn (): ?array => $this->productVariant === null ? null : [
                'id' => $this->productVariant->id,
                'label' => $this->productVariant->label,
            ]),

            'assigned_to_user_id' => $this->assigned_to_user_id,
            'assignee' => $this->whenLoaded('assignee', fn (): ?array => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
                'employee_code' => $this->assignee->employee_code,
            ]),

            'created_by_user_id' => $this->created_by_user_id,
            'creator' => $this->whenLoaded('creator', fn (): ?array => $this->creator === null ? null : [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),

            'description' => $this->description,

            // §٩'s table, on the detail screen alone — a list of forty shortages has no use for
            // four hundred ledger rows.
            'supplies' => ShortageSupplyResource::collection($this->whenLoaded('supplies')),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
