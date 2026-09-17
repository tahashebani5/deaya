<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Models\NawrisParcelOrder;
use App\Domain\Order\Models\Order;

/**
 * Lets go of a parcel without telling the carrier anything.
 *
 * **The one operation here that makes no HTTP call, and that is its entire reason to exist.**
 * When somebody deletes a parcel in the Nawris portal, nothing reaches us: no webhook we have
 * ever seen, and our row goes on saying a parcel is out. The order is then stuck — «إرسال
 * للنورس» refuses it because a parcel is open, and {@see DeleteNawrisParcel} would ask them to
 * delete something that is already gone and earn an error for it. This is the way out: we stop
 * claiming the link, and the order can go again.
 *
 * **Soft-deleted, never destroyed.** The parcel really was lodged, and the reference we minted is
 * how their support finds it months later. The link row is what the dispatch guard reads — the
 * relation excludes soft-deleted rows — so hiding it is exactly enough and nothing has to be
 * thrown away to achieve it.
 *
 * The parcel is closed too, so it stops appearing in "still out there" queues that read
 * `closed_at` rather than the links.
 */
final class DetachNawrisParcel
{
    public function __invoke(NawrisParcel $parcel, Order $order): NawrisParcel
    {
        NawrisParcelOrder::query()
            ->where('nawris_parcel_id', $parcel->getKey())
            ->where('order_id', $order->getKey())
            ->get()
            // One at a time rather than a mass `delete()`: each row is audited, and a mass update
            // fires no model events — see the audit convention every model here is held to.
            ->each(fn (NawrisParcelOrder $link) => $link->delete());

        $parcel->forceFill([
            'closed_at' => now(),
            'remote_status_text' => 'فُكّ ربطها بالطلبية',
        ])->save();

        return $parcel;
    }
}
