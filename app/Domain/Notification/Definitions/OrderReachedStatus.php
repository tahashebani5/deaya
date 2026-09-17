<?php

declare(strict_types=1);

namespace App\Domain\Notification\Definitions;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Notification\Audience\NotificationAudience;
use App\Domain\Notification\Contracts\NotificationDefinition;
use App\Domain\Notification\DTOs\RenderedNotification;
use App\Domain\Notification\Listeners\NotifyWhenOrderStatusChanges;
use App\Domain\Order\Enums\OrderStatus;

/**
 * An order reached a status somebody away from the screen needs to know about.
 *
 * **One definition for every milestone rather than one per status.** The fifteen statuses share
 * an audience, a route and a sentence; the only thing that differs is the name, and the name
 * already lives on {@see OrderStatus::label()}. Fifteen classes would be fifteen copies of one
 * decision, and the day the audience changes it would change in fifteen places.
 *
 * Which statuses get here at all is {@see NotifyWhenOrderStatusChanges}'s
 * business, not this class's — it renders whatever it is handed.
 */
final readonly class OrderReachedStatus implements NotificationDefinition
{
    /**
     * Everybody who may read orders.
     *
     * The same answer {@see OrderReachedShortage} gives, for the same reason: the business has
     * already decided who reads orders on the roles screen, and asking a second time would mean
     * keeping two answers in step. A role added next year hears these the day it exists.
     *
     * @param  array<string, mixed>  $payload
     */
    public function audience(array $payload): NotificationAudience
    {
        return NotificationAudience::permission(PermissionName::ViewOrders);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(array $payload): RenderedNotification
    {
        $code = (string) ($payload['order_code'] ?? '');
        $customer = (string) ($payload['customer_name'] ?? '');
        $to = $this->label($payload['to_status'] ?? null);
        $from = $this->label($payload['from_status'] ?? null);

        return new RenderedNotification(
            title: "طلبية {$code} — {$to}",
            // Who it is for comes first: that is what tells a reader whether this is the order
            // they were waiting on. Where it came from is the fallback, because an order with no
            // customer name still needs the move to read as a move.
            body: $customer !== '' ? "العميل: {$customer} · من {$from}" : "من {$from} إلى {$to}",
            route: '/orders/'.($payload['order_id'] ?? ''),
        );
    }

    /**
     * The person who moved the order watched it happen on their own screen.
     */
    public function notifiesCauser(): bool
    {
        return false;
    }

    /**
     * The Arabic name of a status, read from the enum at render time rather than frozen.
     *
     * **The one thing this payload deliberately does not snapshot.** Everything else is frozen so
     * the sentence survives the order changing — but a status label is not a fact about that
     * order, it is wording the shop owns, and fixing a typo in it should fix every notification
     * that has ever said it rather than only the ones sent from tomorrow.
     *
     * `tryFrom` because a status retired years from now must still render the rows that named
     * it: the stored value is shown as-is rather than throwing inside a mailbox.
     */
    private function label(mixed $value): string
    {
        $value = is_string($value) ? $value : '';

        return OrderStatus::tryFrom($value)?->label() ?? $value;
    }
}
