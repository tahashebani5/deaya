<?php

namespace Database\Factories;

use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\TicketMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketMessage>
 *
 * **No author by default**, which would violate the table's CHECK — so every caller states who
 * wrote it, with `fromCustomer()` or `fromStaff()`. A default author would be a default answer
 * to the one question this row exists to record.
 */
class TicketMessageFactory extends Factory
{
    /** @var class-string<TicketMessage> */
    protected $model = TicketMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_ticket_id' => SupportTicket::factory(),
            'body' => fake()->sentence(),
        ];
    }

    public function fromCustomer(int $customerId): static
    {
        return $this->state(fn () => ['customer_id' => $customerId, 'user_id' => null]);
    }

    public function fromStaff(int $userId): static
    {
        return $this->state(fn () => ['user_id' => $userId, 'customer_id' => null]);
    }
}
