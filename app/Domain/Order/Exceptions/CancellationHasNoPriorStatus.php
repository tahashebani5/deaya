<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A cancelled order whose timeline does not say where it was cancelled *from*.
 *
 * **The one condition on undoing a cancellation, and the reason it is a refusal rather than a
 * fallback.** «تراجع عن الإلغاء» puts the order back exactly where it stood — read from
 * `order_status_transitions`, never chosen by the caller — because an undo that let somebody
 * name the destination would be a second, unguarded way into any status the machine's map
 * deliberately makes unreachable from «إلغاء تام».
 *
 * So an order with no recorded cancelling move has nowhere to be put back to, and guessing one
 * would be the very thing this endpoint exists not to do. Orders imported before the timeline
 * existed are the case that reaches this.
 */
final class CancellationHasNoPriorStatus extends DomainException
{
    public static function make(): self
    {
        return new self('لا يوجد في سجل الطلبية الحالة التي أُلغيت منها، فلا يمكن التراجع عن الإلغاء');
    }
}
