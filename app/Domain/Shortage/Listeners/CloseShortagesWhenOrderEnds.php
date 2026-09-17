<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Listeners;

use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Events\OrderProfitUnwound;
use App\Domain\Order\Events\OrderStatusChanged;
use App\Domain\Shortage\Actions\CloseShortagesForOrder;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Stops chasing an order's shortages once the order itself has ended.
 *
 * **Two endings, one listener, because the shortages do not care which it was.** A cancellation
 * says the order happened and then stopped; a delete says it should never have been written down
 * — a distinction `DeleteOrder` spends a paragraph on, and one that makes no difference at all to
 * a sack nobody is going to buy any more.
 *
 * `OrderProfitUnwound` is the delete's own announcement, fired from inside `DeleteOrder`, and
 * using it is what keeps this integration out of `Domain/Order` entirely — the same road
 * `UnwindEarningsWhenOrderIsDeleted` takes for the same event.
 *
 * **Neither ending reverses a supply**, and that is a decision rather than an omission — see
 * {@see CloseShortagesForOrder} and SHORTAGES-DESIGN §٧٫٣. Completed shortages are not touched at
 * all: «الاحتفاظ بالنواقص المكتملة كسجلٍّ تاريخي».
 *
 * Queued and after commit, like every other listener in this context.
 */
final class CloseShortagesWhenOrderEnds implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly CloseShortagesForOrder $close) {}

    public function handleCancellation(OrderStatusChanged $event): void
    {
        // Every other status change is the sync's business, not this one's — and «إلغاء تام» is
        // the only one that ends an order without deleting it.
        if ($event->to !== OrderStatus::Cancelled) {
            return;
        }

        ($this->close)($event->orderId);
    }

    public function handleDeletion(OrderProfitUnwound $event): void
    {
        ($this->close)($event->orderId);
    }
}
