<?php

declare(strict_types=1);

namespace App\Domain\Notification\Definitions;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Notification\Audience\NotificationAudience;
use App\Domain\Notification\Contracts\NotificationDefinition;
use App\Domain\Notification\DTOs\RenderedNotification;

/**
 * An order could not be filled from the shelf and is now waiting on a person.
 *
 * **The first notification in the system, chosen because «نواقص» is the status that most needs
 * somebody to act.** An order sitting in shortage is not progressing and nothing else in the
 * application says so out loud — it is exactly the case where a bell earns its place.
 *
 * A worked example of the whole extension point: an audience of one line, a sentence built from
 * frozen facts, and a route. No migration, no endpoint, no client change.
 */
final readonly class OrderReachedShortage implements NotificationDefinition
{
    /**
     * Everybody who may read orders.
     *
     * Not a hand-kept list of «who cares about shortages» — the business already answered this
     * question on the roles screen, and asking it twice would mean maintaining two answers.
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

        return new RenderedNotification(
            title: "طلبية {$code} في النواقص",
            // The customer's name rather than the missing quantity: whoever reads this is
            // deciding whether to call somebody, and the shortfall per line is on the screen
            // the route opens.
            body: $customer !== '' ? "العميل: {$customer}" : 'الطلبية بانتظار توفّر المواد',
            route: '/orders/'.($payload['order_id'] ?? ''),
        );
    }

    /**
     * The person who moved the order into shortage watched it happen on their own screen.
     */
    public function notifiesCauser(): bool
    {
        return false;
    }
}
