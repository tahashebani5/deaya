<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The number beside each row of the status filter.
 *
 * Without it, the only way to learn there are no returns today is to tap «رواجع» and meet an
 * empty screen — a round trip and a disappointment to answer something the server could have
 * said with the page.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderStatusCountsTest extends TestCase
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
    private function viewer(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewOrders->value);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_it_counts_the_orders_in_each_status(): void
    {
        // Arrange
        Order::factory()->count(3)->status(OrderStatus::Printing)->create();
        Order::factory()->count(2)->status(OrderStatus::Ready)->create();
        Order::factory()->status(OrderStatus::Delivered)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/summary');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.counts.printing', 3)
            ->assertJsonPath('data.counts.ready', 2)
            ->assertJsonPath('data.counts.delivered', 1)
            ->assertJsonPath('data.total', 6);
    }

    public function test_a_status_with_nothing_in_it_is_a_zero_not_a_gap(): void
    {
        // Arrange
        Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/summary');

        // Assert — a missing key would leave the app choosing between a blank and a zero, and
        // those mean different things.
        foreach (OrderStatus::cases() as $status) {
            $response->assertJsonPath("data.counts.{$status->value}", fn ($v) => is_int($v));
        }

        $response->assertJsonPath('data.counts.cancelled', 0);
    }

    public function test_the_counts_describe_the_same_set_the_search_narrowed(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        Order::factory()->count(2)->forCustomer($customer)->status(OrderStatus::Printing)->create();
        Order::factory()->count(5)->status(OrderStatus::Printing)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/orders/summary?search={$customer->code}");

        // Assert — a count that ignored the search would say 7 next to a list showing 2.
        $response->assertOk()
            ->assertJsonPath('data.counts.printing', 2)
            ->assertJsonPath('data.total', 2);
    }

    public function test_the_status_filter_itself_is_ignored(): void
    {
        // Arrange
        Order::factory()->count(3)->status(OrderStatus::Printing)->create();
        Order::factory()->count(2)->status(OrderStatus::Ready)->create();
        $headers = $this->viewer();

        // Act — the app asks for counts while «قيد الطباعة» is the chosen queue.
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/summary?status=printing');

        // Assert — otherwise every row would show the same number: the list's own length.
        $response->assertOk()
            ->assertJsonPath('data.counts.printing', 3)
            ->assertJsonPath('data.counts.ready', 2);
    }

    public function test_the_word_summary_is_not_read_as_an_order_id(): void
    {
        // Arrange
        Order::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/summary');

        // Assert — the route is declared before the resource for exactly this reason.
        $response->assertOk()->assertJsonStructure(['data' => ['counts', 'total']]);
    }

    public function test_counts_need_the_permission_to_view_orders(): void
    {
        // Arrange
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/summary');

        // Assert
        $response->assertForbidden();
    }
}
