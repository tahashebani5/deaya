<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Order;

use App\Domain\Order\Enums\AdditionalCostReason;
use App\Domain\Order\Enums\DesignSource;
use Illuminate\Validation\Rule;

/**
 * The same shape as creating, minus the two things an order may not change.
 *
 * `customer_id` is absent: an order belongs to whoever placed it, and reassigning one would
 * rewrite two histories to fix a typo. `items` becomes optional — omitting it leaves the lines
 * alone, exactly as omitting `variants` leaves a product's price list alone.
 *
 * Written out in full rather than merged onto the parent's: Scramble reads `rules()` statically
 * to build the request body and cannot follow `array_merge(parent::rules(), …)`, which would
 * publish this endpoint with no documented body at all.
 */
class UpdateOrderRequest extends StoreOrderRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_shop_id' => ['nullable', 'integer', Rule::exists('customer_shops', 'id')->withoutTrashed()],

            'city_id' => ['required', 'integer', Rule::exists('cities', 'id')->withoutTrashed()],
            'region_id' => ['nullable', 'integer', Rule::exists('regions', 'id')->withoutTrashed()],

            'design_source' => ['sometimes', Rule::enum(DesignSource::class)],

            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:20'],
            'address_details' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'design_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],

            // The same three as on the way in — see StoreOrderRequest for why the reason is
            // required as soon as there is an amount to explain.
            'additional_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'additional_cost_reason' => [
                Rule::requiredIf(fn () => (float) $this->input('additional_cost', 0) > 0),
                'nullable',
                Rule::enum(AdditionalCostReason::class),
            ],
            'additional_cost_note' => [
                Rule::requiredIf(
                    fn () => $this->input('additional_cost_reason') === AdditionalCostReason::Other->value
                        && (float) $this->input('additional_cost', 0) > 0
                ),
                'nullable',
                'string',
                'max:500',
            ],

            // Who is making it. Changeable while the order is open — the vendor a job was sent
            // to is exactly the kind of thing that turns out to be the wrong one on the day —
            // and re-snapshotted with its name when it moves; see `UpdateOrder`.
            'vendor_id' => ['nullable', 'integer', Rule::exists('vendors', 'id')->withoutTrashed()],

            'tracking_number' => ['nullable', 'string', 'max:100'],

            // «مستعجلة». `sometimes`, not `nullable`: on an edit the key being absent means
            // «اتركها كما هي», and a null arriving in its place would be a third state nothing
            // downstream has a meaning for. See `OrderData::$isUrgent`.
            'is_urgent' => ['sometimes', 'boolean'],

            // Optional here: omit to leave the lines untouched, send to replace the whole set.
            'items' => ['sometimes', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->withoutTrashed()],
            'items.*.product_variant_id' => ['required', 'integer', Rule::exists('product_variants', 'id')->withoutTrashed()],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:999999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999.999'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],

            // Not accepted here either — see StoreOrderRequest. An edit rebuilds the whole line
            // set, and the only status that holds a measured quantity («جاهزة») is one where
            // `Order::itemsAreEditable()` is already false, so there is nothing here to preserve.
        ];
    }
}
