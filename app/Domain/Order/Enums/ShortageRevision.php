<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

use App\Domain\Order\Actions\SetOrderShortages;

/**
 * Why what is missing from an order has just been rewritten.
 *
 * **Because the numbers cannot say.** A line falling from short-by-thirty to short-by-nothing is
 * either «وصلت البضاعة» or «لا ينقص منها شيء» — the same write, two opposite facts — and anything
 * reacting to it has to tell them apart: the first is a procurement worth recording, the second is
 * the withdrawal of a claim. Inferring it downstream from the order's status was the alternative,
 * and it was wrong in a way worth naming here: whether the status had moved yet depended on
 * whether the listener ran inside the request or off a queue, so the same event meant different
 * things on a developer's laptop and on a server.
 *
 * {@see SetOrderShortages} has exactly three callers and each of them knows which this is, so the
 * answer is passed down rather than reconstructed.
 *
 * This enum is deliberately not persisted anywhere. It describes an event, not a row.
 */
enum ShortageRevision: string
{
    /** Entering «نواقص»: this is what the order could not be started with. */
    case Declared = 'declared';

    /** Leaving «نواقص»: this is what is *still* missing now the delivery has been counted in. */
    case Received = 'received';

    /** Somebody fixing the figure on the order screen, with the order parked where it was. */
    case Corrected = 'corrected';
}
