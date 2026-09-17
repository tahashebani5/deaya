<?php

namespace Database\Factories;

use App\Domain\Customer\Models\Customer;
use App\Domain\Support\Enums\TicketStatus;
use App\Domain\Support\Models\SupportTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    /** @var class-string<SupportTicket> */
    protected $model = SupportTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'subject' => 'استفسار عن '.fake()->word(),
            'status' => TicketStatus::Open,
            // Set so the list's ordering is meaningful without every test writing a message.
            'last_message_at' => now(),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => TicketStatus::InProgress]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => TicketStatus::Closed, 'closed_at' => now()]);
    }
}
