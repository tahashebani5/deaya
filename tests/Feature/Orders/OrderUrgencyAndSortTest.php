<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Two questions a work queue asks about waiting, and the one answer that is not a status.
 *
 * **`sort`** turns the list round: «الأقدم أولاً» is how somebody finds what has been sitting
 * longest, which the default — newest first, the order somebody rang about five minutes ago —
 * cannot show at all past the first page.
 *
 * **`is_urgent`** is a decision, not a measurement. It is set by the person who took the order
 * and travels with it; nothing here derives it from the clock, because an order's age measures
 * *our* delay and the flag records *the customer's* demand, and those part company constantly.
 * See Docs/orders/ORDER-URGENT-AND-SORT.md.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderUrgencyAndSortTest extends TestCase
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
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewOrders);
    }

    /**
     * @return array<string, string>
     */
    private function clerk(): array
    {
        return $this->auth(PermissionName::ViewOrders, PermissionName::ManageOrders);
    }

    /**
     * The body that takes an order, so the flag can be tested on the way in.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $product = Product::factory()->create([
            'pricing_mode' => PricingMode::Tiered,
            'min_order_quantity' => '100',
        ]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->getKey(),
            'label' => '25*35',
        ]);
        ProductPriceTier::factory()->create([
            'product_variant_id' => $variant->getKey(),
            'min_quantity' => '100',
            'unit_price' => '1.100',
        ]);

        return array_merge([
            'customer_id' => Customer::factory()->create()->getKey(),
            'city_id' => City::factory()->create(['delivery_price' => '20.00'])->getKey(),
            'address_details' => 'شارع الجمهورية',
            'items' => [[
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => '300',
            ]],
        ], $overrides);
    }

    /**
     * Three orders taken on three different days, oldest first in the array.
     *
     * `placed_at` rather than `created_at`, because that is the column the list sorts on and the
     * one the date filter already counts — see `FiltersOrders`. Written explicitly and days
     * apart so the assertion is about the ordering and not about how fast the test ran.
     *
     * @return list<Order>
     */
    private function threeDays(): array
    {
        return [
            Order::factory()->create(['placed_at' => now()->subDays(3)]),
            Order::factory()->create(['placed_at' => now()->subDays(2)]),
            Order::factory()->create(['placed_at' => now()->subDay()]),
        ];
    }

    // ────────────────────────────── the order of the list ──────────────────────────────

    public function test_the_list_is_newest_first_by_default(): void
    {
        // Arrange
        [$oldest, , $newest] = $this->threeDays();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert — unchanged by this feature: a screen that asked for nothing gets what it
        // always got.
        $response->assertOk()
            ->assertJsonPath('data.0.id', $newest->getKey())
            ->assertJsonPath('data.2.id', $oldest->getKey());
    }

    public function test_sort_oldest_turns_the_list_round(): void
    {
        // Arrange
        [$oldest, , $newest] = $this->threeDays();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?sort=oldest');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.id', $oldest->getKey())
            ->assertJsonPath('data.2.id', $newest->getKey());
    }

    public function test_two_orders_taken_at_the_same_instant_keep_a_stable_order(): void
    {
        // Arrange — the tie the id is there to break. Without it Postgres is free to hand back
        // either one first, and a row that swaps places between page one and page two is a row
        // read twice and a row never seen.
        $at = now()->subDay();
        $first = Order::factory()->create(['placed_at' => $at]);
        $second = Order::factory()->create(['placed_at' => $at]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?sort=oldest');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.id', $first->getKey())
            ->assertJsonPath('data.1.id', $second->getKey());
    }

    public function test_a_sort_nobody_named_falls_back_to_newest(): void
    {
        // Arrange — a value no enum case matches. Refusing it with a 422 would turn a typo in a
        // query string into a screen with no orders on it; falling back answers the question
        // that was almost asked.
        [$oldest, , $newest] = $this->threeDays();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?sort=sideways');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.id', $newest->getKey())
            ->assertJsonPath('data.2.id', $oldest->getKey());
    }

    public function test_the_sort_survives_the_other_filters(): void
    {
        // Arrange — the axes cross rather than replace each other: «الجاهزة، الأقدم أولاً» is
        // one question.
        $oldReady = Order::factory()->status(OrderStatus::Ready)->create(['placed_at' => now()->subDays(5)]);
        $newReady = Order::factory()->status(OrderStatus::Ready)->create(['placed_at' => now()->subDay()]);
        Order::factory()->status(OrderStatus::New)->create(['placed_at' => now()->subDays(9)]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?sort=oldest&status=ready');

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $oldReady->getKey())
            ->assertJsonPath('data.1.id', $newReady->getKey());
    }

    // ────────────────────────────── the flag ──────────────────────────────

    public function test_an_order_is_not_urgent_unless_somebody_says_so(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload());

        // Assert — false, not null: «لم يُقل عنها شيء» and «ليست مستعجلة» are the same answer.
        $response->assertCreated()->assertJsonPath('data.is_urgent', false);
    }

    public function test_a_clerk_can_take_an_order_as_urgent(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/orders', $this->payload(['is_urgent' => true]));

        // Assert
        $response->assertCreated()->assertJsonPath('data.is_urgent', true);
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'is_urgent' => true,
        ]);
    }

    public function test_an_order_can_be_marked_urgent_after_it_was_taken(): void
    {
        // Arrange — the case the flag exists for: the customer rings two days later.
        $order = Order::factory()->create(['is_urgent' => false]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->getKey()}", [
            'city_id' => $order->city_id,
            'is_urgent' => true,
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.is_urgent', true);
        $this->assertDatabaseHas('orders', ['id' => $order->getKey(), 'is_urgent' => true]);
    }

    public function test_the_flag_can_be_taken_off_again(): void
    {
        // Arrange
        $order = Order::factory()->create(['is_urgent' => true]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->getKey()}", [
            'city_id' => $order->city_id,
            'is_urgent' => false,
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.is_urgent', false);
    }

    public function test_an_edit_that_says_nothing_about_urgency_leaves_it_alone(): void
    {
        // Arrange — the rule `is_active` follows on a customer, and for the same reason: every
        // edit re-sends the whole order, and a field nobody mentioned must not be cleared by
        // somebody correcting an address.
        $order = Order::factory()->create(['is_urgent' => true]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->getKey()}", [
            'city_id' => $order->city_id,
            'notes' => 'اتصل قبل التوصيل',
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.is_urgent', true);
    }

    public function test_the_flag_is_recorded_in_the_history(): void
    {
        // Arrange — «مَن جعلها مستعجلة؟» is the question the flag invites, and an answer that
        // lives nowhere is why it has to go through the ordinary audited write.
        $order = Order::factory()->create(['is_urgent' => false]);
        $headers = $this->clerk();

        // Act
        $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->getKey()}", [
            'city_id' => $order->city_id,
            'is_urgent' => true,
        ])->assertOk();

        // Assert
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => 'order',
            'subject_id' => $order->getKey(),
            'event' => 'updated',
        ]);
    }

    public function test_urgency_is_refused_as_anything_but_a_boolean(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->getKey()}", [
            'city_id' => $order->city_id,
            'is_urgent' => 'ربما',
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('is_urgent');
    }

    public function test_a_viewer_may_not_flag_an_order_urgent(): void
    {
        // Arrange — the flag rides on `orders.manage`, the grant every other edit costs.
        $order = Order::factory()->create(['is_urgent' => false]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->getKey()}", [
            'city_id' => $order->city_id,
            'is_urgent' => true,
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->getKey(), 'is_urgent' => false]);
    }

    public function test_a_closed_order_cannot_be_flagged(): void
    {
        // Arrange — nothing about a delivered order is editable, and «استعجل ما وصل» means
        // nothing. Refused by `UpdateOrder`'s own rule rather than by one written twice.
        $order = Order::factory()->status(OrderStatus::Delivered)->create(['is_urgent' => false]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->getKey()}", [
            'city_id' => $order->city_id,
            'is_urgent' => true,
        ]);

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseHas('orders', ['id' => $order->getKey(), 'is_urgent' => false]);
    }

    // ────────────────────────────── filtering by it ──────────────────────────────

    public function test_the_list_can_be_narrowed_to_the_urgent_ones(): void
    {
        // Arrange
        $urgent = Order::factory()->create(['is_urgent' => true]);
        Order::factory()->create(['is_urgent' => false]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?urgent=1');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $urgent->getKey());
    }

    public function test_urgency_narrows_beside_the_status_rather_than_instead_of_it(): void
    {
        // Arrange — «الجاهزة والمستعجلة» is one question with two answers applied at once, the
        // same way the payment filter crosses the status.
        $wanted = Order::factory()->status(OrderStatus::Ready)->create(['is_urgent' => true]);
        Order::factory()->status(OrderStatus::Ready)->create(['is_urgent' => false]);
        Order::factory()->status(OrderStatus::New)->create(['is_urgent' => true]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?urgent=1&status=ready');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->getKey());
    }

    public function test_asking_for_urgent_false_asks_for_the_calm_ones(): void
    {
        // Arrange — `urgent=0` is a question, not a missing filter: «أرِني ما ليس مستعجلاً».
        Order::factory()->create(['is_urgent' => true]);
        $calm = Order::factory()->create(['is_urgent' => false]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?urgent=0');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $calm->getKey());
    }

    public function test_without_the_filter_both_kinds_are_listed(): void
    {
        // Arrange
        Order::factory()->create(['is_urgent' => true]);
        Order::factory()->create(['is_urgent' => false]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_status_counts_answer_for_the_urgent_set_too(): void
    {
        // Arrange — the numbers beside the filter describe the set on screen, so a filter that
        // narrows the list has to narrow them. Same reason `payment_status` is not ignored there.
        Order::factory()->status(OrderStatus::Ready)->create(['is_urgent' => true]);
        Order::factory()->status(OrderStatus::Ready)->create(['is_urgent' => false]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/summary?urgent=1');

        // Assert
        $response->assertOk()->assertJsonPath('data.total', 1);
    }
}
