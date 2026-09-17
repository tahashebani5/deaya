<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Notification\Contracts\NotificationDefinition;
use App\Domain\Notification\Models\NotificationRecipient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One notification, as the person who received it reads it.
 *
 * ### The sentence is built here, not stored
 *
 * `title`, `body` and `route` come from the type's definition, rendered now from the payload
 * that was frozen when the event happened. Storing the rendered Arabic instead would mean an
 * Arabic typo lived forever in every mailbox that already had it, and a second language could
 * never be added without a migration.
 *
 * ### And it is why a new notification type needs no app release
 *
 * **Everything the client draws is decided on this side.** The app never switches on `type` —
 * it prints the title and body, maps `icon` through a small vocabulary with a plain-bell
 * fallback, and pushes `route` at its router. So a type shipped today shows up correctly in a
 * build compiled last month. The moment a client has to learn a type to render it, that property
 * is gone.
 *
 * @mixin NotificationRecipient
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notification = $this->notification;

        /** @var NotificationDefinition $definition */
        $definition = app($notification->type->definition());
        $rendered = $definition->render($notification->payload);

        return [
            // The notification's id, not the recipient row's — it is what the client marks read
            // and what a push carries, and the join row is an implementation detail.
            'id' => $notification->getKey(),

            // The value and its Arabic label, the same bargain every enum in this API makes:
            // the client renders the label and branches on the value, and never keeps its own
            // translation table in step with ours.
            'type' => $notification->type->value,
            'type_label' => $notification->type->label(),

            'title' => $rendered->title,
            'body' => $rendered->body,

            // A small stable vocabulary — `warning`, `order`, `announcement`. Never an icon
            // name from any particular toolkit: the app owns how it draws.
            'icon' => $notification->type->icon(),

            // **Nullable, and that is ordinary rather than an edge case.** An announcement has
            // nothing to open, so the client must render a tile that simply does not navigate.
            'route' => $rendered->route,

            // A stable morph alias — `order` — never a PHP class name. Null for an announcement,
            // which is about nothing.
            'subject_type' => $notification->subject_type,
            'subject_id' => $notification->subject_id,

            'is_read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
