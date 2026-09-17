<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Enums;

use App\Domain\Delivery\Models\City;

/**
 * How an order reaches its customer from a given place on the delivery map: we take it to them,
 * or they come and collect it.
 *
 * **Stored on the city rather than derived from its name.** The two إستلام مكتب rows are cities
 * like any other — collecting in person is picked from the same list as having something
 * delivered, which is the right shape for the customer and was never the problem. The problem is
 * that the *only* way to tell them apart was to read an Arabic display name, and an order's
 * status is about to depend on the answer: leaving the workshop moves an order to «استلام مكتب»
 * for one kind of city and «جاري التوصيل» for the other, decided by the server rather than
 * chosen by staff. A rule keyed off a display name breaks the first time somebody fixes a typo,
 * and it breaks silently — the symptom is a wrong status on an order, days later and nowhere
 * near the edit that caused it.
 *
 * Deliberately **not** a boolean `is_office_pickup`. A third arrangement is entirely plausible —
 * shipping to another country, or a courier who collects from the workshop — and each of those
 * is a different word for the customer and a different status for the order. A boolean would
 * have to be replaced; a case can be added.
 *
 * Lives in Delivery, and Order reads it from here. Never the other way round: the map knows
 * nothing about orders, which is what lets an order's vocabulary grow without touching it.
 *
 * @see City::isOfficePickup()
 */
enum FulfilmentType: string
{
    /** We take the order to the customer. Nearly every city on the map. */
    case Delivery = 'delivery';

    /** The customer collects it from one of our branches. */
    case OfficePickup = 'office_pickup';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'توصيل',
            self::OfficePickup => 'استلام مكتب',
        };
    }

    public function isOfficePickup(): bool
    {
        return $this === self::OfficePickup;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
