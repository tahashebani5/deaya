<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Support;

use App\Domain\Delivery\DTOs\ShippingCompanyData;
use App\Domain\Delivery\Models\ShippingCompany;

/**
 * The two rules «الشركة الافتراضية» obeys, said once for both the actions that write it.
 *
 * **There is one default, or there is none.** The database is the last word on it — a partial
 * unique index — and this is what keeps the write from ever reaching it: the flag is taken off
 * whoever held it in the same transaction it is given to somebody else.
 *
 * **And it cannot sit on a carrier we no longer deal with.** Switching a company off is how it
 * stops being offered on a dispatch, so a default that survived being switched off would be a
 * company the form opens on and the picker refuses to show.
 */
final class SoleDefaultCarrier
{
    /**
     * What the flag should read after this write.
     *
     * [$current] is what the row says now, which is the answer whenever the request said
     * nothing — see {@see ShippingCompanyData::$isDefault}. A company being retired loses it
     * either way.
     */
    public static function wanted(ShippingCompanyData $data, bool $current): bool
    {
        return ($data->isDefault ?? $current) && $data->isActive;
    }

    /** Takes the flag off every live company but [$keep]. */
    public static function clearAllBut(?int $keep): void
    {
        ShippingCompany::query()
            ->where('is_default', true)
            ->when($keep !== null, fn ($query) => $query->whereKeyNot($keep))
            ->update(['is_default' => false]);
    }
}
