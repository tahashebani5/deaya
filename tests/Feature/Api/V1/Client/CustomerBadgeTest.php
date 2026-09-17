<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Client;

use App\Domain\Customer\Enums\CustomerBadge;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Support\Models\SupportTicket;
use App\Domain\Support\Models\TicketMessage;
use App\Domain\Support\SupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What is waiting for the customer, counted before they open anything.
 *
 * **The badge exists because the app has no way to be told.** There are no sockets and no push
 * notifications, so a reply that arrives while the app is shut is invisible until the customer
 * happens to open «الدعم». A number on the tile is the cheapest honest answer: it is fetched on
 * launch, on resume, and when the home screen is pulled down.
 *
 * **Two promises are tested here and they are different.** That the count is *right* — which
 * means derived from the read cursor, never a stored counter that drifts — and that the answer
 * carries **every badge including the empty ones**, because the app clears a tile from this map
 * and an omitted key would leave the last number on screen forever.
 *
 * Arrange - Act - Assert throughout.
 */
class CustomerBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $phone = '0911111111'): Customer
    {
        return Customer::factory()->registered()->create(['phone' => $phone]);
    }

    /**
     * @return array<string, string>
     */
    private function bearerFor(Customer $customer): array
    {
        return ['Authorization' => 'Bearer '.$customer->createToken('app')->plainTextToken];
    }

    /**
     * A thread for [$customer] carrying [$fromShop] replies from the shop.
     */
    private function threadWithReplies(Customer $customer, int $fromShop): SupportTicket
    {
        $ticket = SupportTicket::factory()->create(['customer_id' => $customer->id]);
        $staff = User::factory()->create();

        // The customer's own opening message, which must never be counted against them.
        TicketMessage::factory()->create([
            'support_ticket_id' => $ticket->id,
            'customer_id' => $customer->id,
            'user_id' => null,
        ]);

        for ($i = 0; $i < $fromShop; $i++) {
            TicketMessage::factory()->create([
                'support_ticket_id' => $ticket->id,
                'user_id' => $staff->id,
                'customer_id' => null,
            ]);
        }

        return $ticket->refresh();
    }

    public function test_it_answers_with_every_badge_even_the_empty_ones(): void
    {
        // Arrange — a customer with nothing waiting at all.
        $me = $this->customer();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/badges');

        // Assert — **the empty answer is the one that matters.** The app clears a tile from this
        // map, so a badge that has just dropped to nothing must arrive saying zero; an endpoint
        // that omitted it would leave the last number on the tile until a reinstall.
        $response->assertOk();

        foreach (CustomerBadge::cases() as $badge) {
            $response->assertJsonPath("data.{$badge->value}", 0);
        }
    }

    public function test_unread_replies_from_the_shop_are_counted(): void
    {
        // Arrange
        $me = $this->customer();
        $this->threadWithReplies($me, 3);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/badges');

        // Assert — the shop's messages only. The customer's own opening line is in that thread
        // and counting it would badge somebody for talking to us.
        $response->assertOk()->assertJsonPath('data.'.CustomerBadge::Support->value, 3);
    }

    public function test_it_counts_across_every_thread_the_customer_owns(): void
    {
        // Arrange — the N+1 this endpoint exists to avoid, and the sum it has to get right.
        $me = $this->customer();
        $this->threadWithReplies($me, 2);
        $this->threadWithReplies($me, 1);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/badges');

        // Assert
        $response->assertOk()->assertJsonPath('data.'.CustomerBadge::Support->value, 3);
    }

    public function test_another_customers_threads_are_never_counted(): void
    {
        // Arrange
        $me = $this->customer();
        $somebodyElse = $this->customer('0922222222');

        $this->threadWithReplies($somebodyElse, 5);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/badges');

        // Assert — the same wall every other read in this API stands behind: scoped in the
        // query, never checked after the fact.
        $response->assertOk()->assertJsonPath('data.'.CustomerBadge::Support->value, 0);
    }

    public function test_reading_the_thread_clears_the_badge(): void
    {
        // Arrange
        $me = $this->customer();
        $ticket = $this->threadWithReplies($me, 2);
        $headers = $this->bearerFor($me);

        $this->withHeaders($headers)->getJson('/api/v1/client/badges')
            ->assertJsonPath('data.'.CustomerBadge::Support->value, 2);

        // Act — opening the thread is what marks it read; there is no «mark as read» endpoint
        // and deliberately so.
        $this->withHeaders($headers)->getJson("/api/v1/client/support/tickets/{$ticket->id}")
            ->assertOk();

        // Assert
        $this->withHeaders($headers)->getJson('/api/v1/client/badges')
            ->assertJsonPath('data.'.CustomerBadge::Support->value, 0);
    }

    public function test_a_reply_after_the_customer_last_read_counts_again(): void
    {
        // Arrange — read the thread, then the shop says something else.
        $me = $this->customer();
        $ticket = $this->threadWithReplies($me, 1);
        $headers = $this->bearerFor($me);

        $this->withHeaders($headers)->getJson("/api/v1/client/support/tickets/{$ticket->id}")
            ->assertOk();

        TicketMessage::factory()->create([
            'support_ticket_id' => $ticket->id,
            'user_id' => User::factory()->create()->id,
            'customer_id' => null,
            'created_at' => now()->addMinute(),
        ]);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/client/badges');

        // Assert — **the cursor, not a counter.** This is the case a stored number gets wrong:
        // it was decremented to zero on the read and nothing increments it again unless every
        // write path remembers to.
        $response->assertOk()->assertJsonPath('data.'.CustomerBadge::Support->value, 1);
    }

    public function test_the_aggregate_agrees_with_the_per_ticket_number(): void
    {
        // Arrange — the same rule is written twice: once per ticket for the thread list, once
        // as one query here. This is what stops the two drifting.
        $me = $this->customer();
        $first = $this->threadWithReplies($me, 2);
        $second = $this->threadWithReplies($me, 3);

        // Act
        $aggregate = app(SupportService::class)->countUnreadForCustomer($me->id);
        $perTicket = $first->unreadFor(staff: false) + $second->unreadFor(staff: false);

        // Assert
        $this->assertSame($perTicket, $aggregate);
        $this->assertSame(5, $aggregate);
    }

    public function test_it_needs_a_signed_in_customer(): void
    {
        // Act
        $response = $this->getJson('/api/v1/client/badges');

        // Assert
        $response->assertUnauthorized();
    }
}
