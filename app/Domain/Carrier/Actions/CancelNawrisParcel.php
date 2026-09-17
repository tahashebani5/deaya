<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Support\NawrisClient;

/**
 * Calls off a live shipment at the carrier.
 *
 * **This is not cancelling the order, and the distinction is the whole design.** «إلغاء تام» is
 * unreachable from «جاري التوصيل» by design — an order is not written off while it is physically
 * outside the building — so calling off a shipment cannot be modelled as a status change. The
 * order stays where it is and the goods come home the ordinary way, by codes 15 → 19 → 6.
 *
 * The parcel is *not* closed here either. It is still out there until something says it came back,
 * and `closed_at` means the journey ended rather than that somebody asked for it to.
 */
final class CancelNawrisParcel
{
    public function __construct(private readonly NawrisClient $client) {}

    public function __invoke(NawrisParcel $parcel): NawrisParcel
    {
        if ($parcel->code === null) {
            return $parcel;
        }

        $this->client->cancelOrder($parcel->code);

        // A note, not a state change — the carrier's own label is what a support screen reads.
        $parcel->forceFill(['remote_status_text' => 'أُلغيت الشحنة بطلب منّا'])->save();

        return $parcel;
    }
}
