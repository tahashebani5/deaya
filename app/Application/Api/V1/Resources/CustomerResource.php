<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Customer\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            // whenLoaded keeps this resource honest: a caller that forgot to eager-load gets
            // no `shops` key rather than a silent query per row.
            'shops' => CustomerShopResource::collection($this->whenLoaded('shops')),
            // How much business this customer has done, when the reader is allowed to know.
            //
            // **Absent rather than zero in every other case**, and the two cases are different
            // facts: a reader without `orders.view` gets no key, and so does the response to a
            // form that has just been saved. A nought would say «this customer has never ordered»
            // on a card that simply was not told. `CustomerController::attachOrderCounts()` is
            // what stamps it, and it is the one place the permission is weighed.
            // `whenHas`, not `whenNotNull`: models run strict here, so *reading* an attribute
            // that was never stamped throws rather than returning null. Asking whether it is
            // there is the only question this resource is allowed to ask.
            'orders_count' => $this->whenHas('orders_count'),
            // When this customer last ordered — sent only on the list that is *sorted* by it,
            // because that is the only screen that shows it and the only request that paid for
            // the subquery. `null` here is a real answer — «لم يطلب أبداً» — which is why the
            // key being absent has to mean something else: «this list was not asked».
            //
            // `array_key_exists`, not `whenHas`: that helper asks the model `offsetExists`,
            // which is false for an attribute holding null — exactly the case that has to
            // survive here. The raw attribute bag is the only thing that can tell «selected,
            // and the answer is none» from «never selected».
            'last_order_at' => $this->when(
                array_key_exists('last_order_at', $this->resource->getAttributes()),
                fn () => $this->last_order_at?->toIso8601String(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
