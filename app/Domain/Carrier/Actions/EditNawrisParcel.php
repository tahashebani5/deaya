<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Actions;

use App\Domain\Carrier\Models\NawrisParcel;
use App\Domain\Carrier\Support\NawrisClient;
use Illuminate\Support\Facades\DB;

/**
 * Tells Nawris the COD changed.
 *
 * **Money is the only thing that can change on a live parcel, so this has exactly one trigger.**
 * `Order::destinationIsEditable()` is false at «جاري التوصيل», so the address and the recipient's
 * phone are frozen by our own domain for precisely the window a parcel is out — and `receiver` is
 * the order code rather than a person's name, so even a name change alters nothing we would send.
 * What is left is a deposit, an installment or a write-off recorded after dispatch.
 *
 * **The destination is replayed off the parcel, never re-derived.** An edit carrying a different
 * area moves the parcel; re-reading the city at edit time is how that happens by accident.
 *
 * **The payload is rebuilt whole.** A field left out is left *untouched* at their end rather than
 * cleared, so a partial edit is a silent no-op dressed as an instruction.
 *
 * **Rebuilt from the parcel, not from the order that triggered it.** With one order per parcel the
 * two agree; the day consolidation arrives they stop agreeing, and an edit built from the
 * triggering order would rewrite the parcel as if its siblings did not exist.
 */
final class EditNawrisParcel
{
    public function __construct(
        private readonly NawrisClient $client,
        private readonly BuildNawrisPayload $payload,
    ) {}

    public function __invoke(NawrisParcel $parcel): NawrisParcel
    {
        $parcel->loadMissing('orders');

        $order = $parcel->orders->first();

        if ($order === null || $parcel->code === null) {
            return $parcel;
        }

        $body = $this->payload->forOrder(
            $order,
            $parcel->government,
            $parcel->area,
            $parcel->code,
        );

        // The reference never changes: changing it detaches the shipment from this record.
        $body['remote_order_id'] = $parcel->reference;

        $this->client->editOrder($body);

        $amount = $this->payload->amountToCollect($order);

        return DB::transaction(function () use ($parcel, $amount): NawrisParcel {
            // Rewritten on every successful edit, so what we believe they are collecting stays
            // what we actually asked for.
            $parcel->forceFill(['amount_to_collect' => $amount])->save();

            $parcel->links()->update(['amount_to_collect' => $amount]);

            return $parcel;
        }, attempts: 3);
    }
}
