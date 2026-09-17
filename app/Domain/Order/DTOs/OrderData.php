<?php

declare(strict_types=1);

namespace App\Domain\Order\DTOs;

use App\Domain\Order\Enums\AdditionalCostReason;
use App\Domain\Order\Enums\DesignSource;

/**
 * Everything a request may say about an order.
 *
 * Money that a client could invent is absent: `items_total` and `grand_total` are derived from
 * the lines, and `delivery_price` is copied from the destination city. What remains — the design
 * fee, the discount and the additional cost — are decisions a person makes, and each is guarded:
 * the fee only counts when we did the design, and the other two need a permission each.
 *
 * `customerId` is read on create and ignored on update: an order belongs to whoever placed it,
 * and moving one between customers would rewrite two histories to fix one typo. Cancel and
 * re-take it instead.
 */
final readonly class OrderData
{
    /**
     * @param  list<OrderItemData>|null  $items  null means "not supplied" — on update the
     *                                           existing lines are left untouched.
     * @param  list<int>  $designIds  Read on create only, and in the order they were sent: the
     *                                version numbers follow the clerk's picking order, so
     *                                «التصميم الأول» means the one they chose first. On update it
     *                                is ignored — a version is added and reviewed through its own
     *                                endpoint, where the conversation with the customer lives.
     */
    public function __construct(
        public int $customerId,
        public int $cityId,
        public ?int $customerShopId = null,
        public ?int $regionId = null,
        public DesignSource $designSource = DesignSource::None,
        public ?string $recipientName = null,
        public ?string $recipientPhone = null,
        public ?string $addressDetails = null,
        public ?string $notes = null,
        public string $designFee = '0.00',
        public string $discount = '0.00',
        public string $additionalCost = '0.00',
        public ?AdditionalCostReason $additionalCostReason = null,
        public ?string $additionalCostNote = null,
        /**
         * Who is making it, for an order دعاية sells and somebody else executes.
         *
         * An id from the vendor list, never a name typed into a box — see OUTSOURCED-PRODUCTS.md
         * §5. Null on every other order, and refused by `CreateOrder` on a وسيط one.
         */
        public ?int $vendorId = null,
        public ?string $trackingNumber = null,
        /**
         * «مستعجلة»، and **null means «لم يُذكر» rather than «لا»**.
         *
         * Every edit re-sends the whole order, so a boolean that defaulted to false would let
         * somebody correcting an address quietly clear a flag they never saw. The same rule
         * `is_active` follows on a customer, and for the same reason. Read on create as false.
         */
        public ?bool $isUrgent = null,
        public ?array $items = null,
        public array $designIds = [],
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            customerId: (int) ($validated['customer_id'] ?? 0),
            cityId: (int) $validated['city_id'],
            customerShopId: isset($validated['customer_shop_id'])
                ? (int) $validated['customer_shop_id']
                : null,
            regionId: isset($validated['region_id']) ? (int) $validated['region_id'] : null,
            designSource: isset($validated['design_source'])
                ? DesignSource::from((string) $validated['design_source'])
                : DesignSource::None,
            recipientName: self::textOrNull($validated['recipient_name'] ?? null),
            recipientPhone: self::textOrNull($validated['recipient_phone'] ?? null),
            addressDetails: self::textOrNull($validated['address_details'] ?? null),
            notes: self::textOrNull($validated['notes'] ?? null),
            // Through string, never float: these are added to a total that must stay exact.
            designFee: self::money($validated['design_fee'] ?? null),
            discount: self::money($validated['discount'] ?? null),
            additionalCost: self::money($validated['additional_cost'] ?? null),
            // Kept even when the amount is zero, the same way the design fee is: somebody who
            // clears the box has not necessarily changed their mind about why.
            additionalCostReason: isset($validated['additional_cost_reason'])
                ? AdditionalCostReason::from((string) $validated['additional_cost_reason'])
                : null,
            additionalCostNote: self::textOrNull($validated['additional_cost_note'] ?? null),
            vendorId: isset($validated['vendor_id']) ? (int) $validated['vendor_id'] : null,
            trackingNumber: self::textOrNull($validated['tracking_number'] ?? null),
            // `array_key_exists`, not `??`: the key being absent is the whole signal, and a
            // `false` that arrived deliberately must not read the same as one that never came.
            isUrgent: array_key_exists('is_urgent', $validated)
                ? (bool) $validated['is_urgent']
                : null,
            designIds: is_array($validated['design_ids'] ?? null)
                ? array_values(array_map(intval(...), $validated['design_ids']))
                : [],
            items: array_key_exists('items', $validated) && is_array($validated['items'])
                ? array_values(array_map(
                    fn (array $item, int $index) => OrderItemData::fromArray($item, $index),
                    $validated['items'],
                    array_keys($validated['items']),
                ))
                : null,
        );
    }

    private static function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? $text : null;
    }
}
