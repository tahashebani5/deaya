<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Support\NawrisClient;

/**
 * Deletes the parcel at the carrier, and closes ours.
 *
 * **Different from {@see CancelNawrisParcel}, and the difference is where the goods are.**
 * Cancelling calls off a shipment that is already moving: the parcel stays open here because it
 * is still out there and has to come home through the return chain. Deleting is for a parcel that
 * never went anywhere — the wrong address, the wrong order, a hand-over somebody wants to redo —
 * so it stops existing on both sides and the order is free to be sent again.
 *
 * **`closed_at` is what frees it.** The dispatch guard asks "is there an open parcel for this
 * order", reading that column rather than a list of statuses, so closing here is the whole
 * mechanism — see {@see DispatchToNawris}.
 *
 * The row is kept. A parcel that was really lodged is a thing that really happened, and their
 * support finds it by the reference we minted.
 */
final class DeleteNawrisParcel
{
    public function __construct(private readonly NawrisClient $client) {}

    public function __invoke(NawrisParcel $parcel): NawrisParcel
    {
        if ($parcel->code !== null) {
            $this->client->deleteOrder($parcel->code);
        }

        $parcel->forceFill([
            'closed_at' => now(),
            // Their own label is what a support screen reads, so ours says who did it.
            'remote_status_text' => 'حُذفت الشحنة بطلب منّا',
        ])->save();

        return $parcel;
    }
}
