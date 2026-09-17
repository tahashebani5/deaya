<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Application\Api\V1\Resources\Client\ClientSupportTicketResource;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A support thread as the desk answering it sees one — who it is from, whose desk it is on, and
 * how long it has been waiting.
 *
 * The customer's version is {@see ClientSupportTicketResource}
 * and carries none of that.
 *
 * @mixin SupportTicket
 */
class SupportTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'customer' => $this->whenLoaded('customer', fn (): ?array => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),

            'order' => $this->whenLoaded('order', fn (): ?array => $this->order === null ? null : [
                'id' => $this->order->id,
                'code' => $this->order->code,
            ]),

            'assigned_to' => $this->assigned_to,
            'assignee' => $this->whenLoaded('assignee', fn (): ?array => $this->assignee === null ? null : [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
            ]),

            // The desk's own unread count — the customer's messages this side has not read.
            'unread_count' => $this->unreadFor(staff: true),

            'messages' => $this->whenLoaded(
                'messages',
                fn () => $this->messages->map(fn (TicketMessage $m) => [
                    'id' => $m->id,
                    'from' => $m->isFromCustomer() ? 'customer' : 'staff',
                    // Named on this side, unlike the customer's: «من ردّ عليه؟» is a question the
                    // shop is entitled to ask of itself.
                    'author_name' => $m->isFromCustomer() ? null : $m->author?->name,
                    'body' => $m->body,
                    'sent_at' => $m->created_at?->toIso8601String(),
                ])->all(),
            ),

            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
