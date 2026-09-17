<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The numbers the home screen opens on.
 *
 * **They were placeholders until now** — a fixed snapshot shipped in the app so the screen could
 * be built before there was anything to count. This is the endpoint that replaces them, and the
 * things worth pinning are the two that a naive implementation gets wrong: which day «اليوم»
 * means, and that a status with nothing in it still has to be sent.
 *
 * Arrange - Act - Assert throughout.
 */
class HomeSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * @return array<string, string>
     */
    private function staff(): array
    {
        $user = User::factory()->create();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ─────────────────────────────── the four counts ───────────────────────────────

    public function test_the_screen_opens_on_what_is_actually_in_the_database(): void
    {
        // Arrange — the four orders belong to one of the three customers, so the two numbers
        // stay independent. An order factory makes a customer of its own otherwise, and the
        // test would be asserting the fixture rather than the count.
        $customers = Customer::factory()->count(3)->create();
        Order::factory()->count(4)->forCustomer($customers->first())->create();
        $headers = $this->staff();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/home/summary');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.customers_count', 3)
            ->assertJsonPath('data.total_orders', 4)
            ->assertJsonPath('data.daily_orders', 4)
            ->assertJsonPath('data.monthly_orders', 4);
    }

    public function test_an_inactive_customer_is_still_a_customer(): void
    {
        // Arrange — customers are deactivated rather than deleted, so «عدد العملاء» counts
        // everyone the shop has ever dealt with.
        Customer::factory()->count(2)->create();
        Customer::factory()->create(['is_active' => false]);
        $headers = $this->staff();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/home/summary');

        // Assert
        $response->assertOk()->assertJsonPath('data.customers_count', 3);
    }

    public function test_a_deleted_customer_is_not(): void
    {
        // Arrange
        Customer::factory()->count(2)->create();
        Customer::factory()->create()->delete();
        $headers = $this->staff();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/home/summary');

        // Assert — soft-deleted rows are gone from every list; a count that included them would
        // be the one number disagreeing with every screen.
        $response->assertOk()->assertJsonPath('data.customers_count', 2);
    }

    // ────────────────────────── which day «اليوم» means ──────────────────────────

    public function test_today_is_the_shop_s_day_and_not_the_server_s(): void
    {
        // Arrange — 00:30 in Tripoli on the 15th is 22:30 UTC on the *14th*. An order taken then
        // belongs to the 15th, which is the day the person reading the screen is living in.
        $business = new \DateTimeZone(config('app.business_timezone'));
        $localMidnightish = Carbon::create(2026, 8, 15, 0, 30, 0, $business);

        Order::factory()->create(['placed_at' => $localMidnightish->copy()->utc()]);
        // And one from the small hours of the day before, which must not be counted.
        Order::factory()->create([
            'placed_at' => $localMidnightish->copy()->subDay()->utc(),
        ]);

        Carbon::setTestNow($localMidnightish->copy()->addHours(9)->utc());
        $headers = $this->staff();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/home/summary');

        // Assert — a naive UTC `whereDate` puts the first order in yesterday, and the number is
        // quietly wrong for the first two hours of every day.
        $response->assertOk()
            ->assertJsonPath('data.daily_orders', 1)
            ->assertJsonPath('data.total_orders', 2);

        Carbon::setTestNow();
    }

    public function test_last_month_s_orders_are_not_this_month_s(): void
    {
        // Arrange
        Carbon::setTestNow(Carbon::create(2026, 8, 6, 12, 0, 0, 'UTC'));

        Order::factory()->count(2)->create(['placed_at' => Carbon::now()]);
        Order::factory()->create(['placed_at' => Carbon::now()->subMonth()]);
        $headers = $this->staff();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/home/summary');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.monthly_orders', 2)
            ->assertJsonPath('data.total_orders', 3);

        Carbon::setTestNow();
    }

    // ─────────────────────────────── the status board ───────────────────────────────

    public function test_every_status_is_sent_including_the_empty_ones(): void
    {
        // Arrange
        Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->staff();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/home/summary');

        // Assert — a missing key would leave the app choosing between a blank and a zero, and
        // the two mean different things: zero is an answer, blank is "we did not ask".
        $statuses = $response->assertOk()->json('data.statuses');

        $this->assertCount(count(OrderStatus::cases()), $statuses);
        $this->assertSame(
            array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases()),
            array_column($statuses, 'status'),
        );
    }

    public function test_each_status_carries_the_word_the_rest_of_the_app_uses_for_it(): void
    {
        // Arrange
        Order::factory()->count(2)->status(OrderStatus::Shortage)->create();
        $headers = $this->staff();

        // Act
        $statuses = $this->withHeaders($headers)
            ->getJson('/api/v1/home/summary')
            ->json('data.statuses');

        $shortage = collect($statuses)->firstWhere('status', OrderStatus::Shortage->value);

        // Assert — the app holds no translation table, so «نواقص» arrives with the number.
        $this->assertSame(OrderStatus::Shortage->label(), $shortage['label']);
        $this->assertSame(2, $shortage['count']);
    }

    public function test_a_shop_that_has_taken_nothing_yet_gets_zeros_rather_than_a_failure(): void
    {
        // Arrange — the first morning, before anything exists.
        $headers = $this->staff();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/home/summary');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.total_orders', 0)
            ->assertJsonPath('data.customers_count', 0);

        $this->assertSame(
            [0],
            array_values(array_unique(array_column($response->json('data.statuses'), 'count'))),
        );
    }

    // ─────────────────────────────── who may read it ───────────────────────────────

    public function test_the_home_screen_needs_a_signed_in_user(): void
    {
        // Act
        $response = $this->getJson('/api/v1/home/summary');

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_member_of_staff_with_no_grants_still_gets_their_home_screen(): void
    {
        // Arrange — a designer holds `orders.status.designing` and nothing else. Guarding this
        // on `orders.view` would hand them a broken front door on every launch.
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::MoveOrderToDesigning->value);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        Order::factory()->create();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/home/summary');

        // Assert
        $response->assertOk()->assertJsonPath('data.total_orders', 1);
    }
}
