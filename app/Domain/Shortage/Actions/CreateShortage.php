<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Shortage\DTOs\ShortageData;
use App\Domain\Shortage\Enums\ShortageSource;
use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Exceptions\ShortageNeedsAQuantity;
use App\Domain\Shortage\Models\Shortage;

/**
 * Writes down a shortage nobody's order produced.
 *
 * **Manual only, and it never touches an order.** The two sources are kept apart at the door
 * rather than merged behind one entry point taking optional order fields: an order-born shortage
 * has a reconciliation behind it ({@see SyncShortagesFromOrder}) that a manual one must never
 * acquire, and an endpoint that could create one would be a way to write a row the order knows
 * nothing about — which the next sync would then delete as an orphan.
 *
 * The status is always «جديد». Creating a shortage that is already being chased is not a thing
 * that happens, and letting a payload say otherwise would let a screen skip the step the board
 * counts.
 */
final class CreateShortage
{
    public function __invoke(ShortageData $data, ?User $actor = null): Shortage
    {
        // The request says the same thing and so does a CHECK — this is the layer a console
        // command meets. RULES §8.
        if (bccomp($data->requiredQuantity, '0', 3) <= 0) {
            throw ShortageNeedsAQuantity::make();
        }

        $shortage = new Shortage;

        $shortage->forceFill([
            'source' => ShortageSource::Manual,
            'name' => $data->name,
            'unit' => $data->unit,
            'required_quantity' => $data->requiredQuantity,
            // Written rather than left to the column defaults: an unsaved model does not read
            // them back, so the row returned to the client would carry '' where a screen expects
            // «٠٫٠٠٠» — and a refresh to fetch two zeros is a query for nothing.
            'supplied_quantity' => '0.000',
            'total_paid' => '0.00',
            'product_id' => $data->productId,
            'product_variant_id' => $data->productVariantId,
            'description' => $data->description,
            'status' => ShortageStatus::New,
            // **Assignable at creation, unlike an order-born one.** Somebody writing a shortage
            // down by hand usually knows who is going to chase it — often themselves — and making
            // them save and then assign is two screens for one thought. An order produces its
            // shortages with nobody watching, which is why the sync leaves them unassigned.
            'assigned_to_user_id' => $data->assignedToUserId,
            'created_by_user_id' => $actor?->getKey(),
        ])->save();

        return $shortage;
    }
}
