<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Client;

use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderFlow;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Vendor\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A customer placing an order from the app.
 *
 * **The order is born «بانتظار المراجعة», and that is the whole feature.** «جديدة» means a
 * person checked it — a clerk who typed an order down spoke to the customer first — and from
 * «جديدة» the next move takes goods off the shelf. Nothing checked an order that arrived from a
 * phone at 2am, so it waits to be read.
 *
 * **The outsourcing rule is deferred to acceptance**, and the four tests at the bottom are the
 * whole of that arrangement: a customer cannot name a vendor, so a request is allowed to be
 * incomplete in exactly that one way, and «بانتظار المراجعة» → «جديدة» is refused until somebody
 * names one. A request may be incomplete; an order may not.
 *
 * Arrange - Act - Assert throughout.
 */
class ClientOrderRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The staff half of this test reaches a `can:`-guarded route, and Spatie refuses to grant a
     * name it has never seen. The suite runs migrations and no seeder, so the rows are made
     * here — the same setUp `OrderTest` carries, and for the same reason.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

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
     * @return array<string, string>
     */
    private function staff(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * A product with one size and one price break. [$mode] decides the road: an outsourced
     * category is what puts an order on the vendor road.
     */
    private function product(ProductionMode $mode = ProductionMode::InHouse): Product
    {
        $category = ProductCategory::factory()->create(['production_mode' => $mode]);
        $product = Product::factory()->create(['product_category_id' => $category->id]);

        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);

        ProductPriceTier::factory()->create([
            'product_variant_id' => $variant->id,
            'min_quantity' => 1,
            'unit_price' => '0.500',
        ]);

        return $product->refresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'city_id' => City::factory()->create()->id,
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => $product->variants()->first()->id,
                'quantity' => 1000,
            ]],
        ], $overrides);
    }

    /**
     * A line for [$product], so an order can be built out of several products.
     *
     * @return array<string, mixed>
     */
    private function line(Product $product, float $quantity = 1000): array
    {
        return [
            'product_id' => $product->id,
            'product_variant_id' => $product->variants()->first()->id,
            'quantity' => $quantity,
        ];
    }

    // ───────────────── whose bench the lines are made on ─────────────────

    public function test_an_order_may_hold_several_products(): void
    {
        // Arrange — the cart. Two of ours, in one order.
        $me = $this->customer();
        $payload = $this->payload($this->product(), [
            'items' => [
                $this->line($this->product(ProductionMode::InHouse)),
                $this->line($this->product(ProductionMode::None), 500),
            ],
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/orders', $payload);

        // Assert — «سادة» beside «مطبوعة» is the standard road, exactly as it was before the
        // rule below existed.
        $response->assertCreated()->assertJsonCount(2, 'data.items');
        $this->assertSame(OrderFlow::Standard, Order::query()->sole()->production_flow);
    }

    public function test_a_vendors_product_cannot_share_an_order_with_ours(): void
    {
        // Arrange — the order that used to be taken and quietly put on the standard road: it
        // would walk «قيد الطباعة» for goods no press of ours touches, and `deductsStock()`
        // would ask a warehouse for goods that were never on a shelf of ours.
        $me = $this->customer();
        $payload = $this->payload($this->product(), [
            'items' => [
                $this->line($this->product(ProductionMode::Outsourced)),
                $this->line($this->product(ProductionMode::InHouse)),
            ],
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/orders', $payload);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_refusal_holds_whichever_way_round_the_lines_come(): void
    {
        // Arrange — the guard reads a set, not the first line.
        $me = $this->customer();
        $payload = $this->payload($this->product(), [
            'items' => [
                $this->line($this->product(ProductionMode::None)),
                $this->line($this->product(ProductionMode::Outsourced)),
            ],
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/orders', $payload);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_several_vendor_products_may_share_one_order(): void
    {
        // Arrange — the rule is unanimity, not a limit on lines. The road is unanimous and the
        // vendor is named once, at acceptance.
        $me = $this->customer();
        $payload = $this->payload($this->product(), [
            'items' => [
                $this->line($this->product(ProductionMode::Outsourced)),
                $this->line($this->product(ProductionMode::Outsourced), 250),
            ],
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/orders', $payload);

        // Assert
        $response->assertCreated();
        $this->assertSame(OrderFlow::Outsourced, Order::query()->sole()->production_flow);
    }

    public function test_a_refused_order_leaves_nothing_behind(): void
    {
        // Arrange — the guard runs inside the transaction, after the lines are written. A
        // refusal that left the rows behind would leave an order with a customer, a city and no
        // status anybody meant.
        $me = $this->customer();
        $payload = $this->payload($this->product(), [
            'items' => [
                $this->line($this->product(ProductionMode::Outsourced)),
                $this->line($this->product(ProductionMode::InHouse)),
            ],
        ]);

        // Act
        $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/orders', $payload);

        // Assert
        $this->assertSame(0, Order::query()->withTrashed()->count());
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_the_clerks_door_is_refused_too(): void
    {
        // Arrange — one rule, and no way round it. `CreateOrder` is the only door either app
        // comes through, which is why the guard lives there rather than in a request.
        $customer = $this->customer();
        $headers = $this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders);

        $payload = [
            'customer_id' => $customer->id,
            'city_id' => City::factory()->create()->id,
            'items' => [
                $this->line($this->product(ProductionMode::Outsourced)),
                $this->line($this->product(ProductionMode::InHouse)),
            ],
        ];

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $payload);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame(0, Order::query()->count());
    }

    // ─────────────────────── the status it is born in ───────────────────────

    /**
     * **The test this whole feature exists for.** The column's own default is still `'new'`, so
     * an insert that forgot to name the status would quietly produce a verified order — this is
     * what catches that.
     */
    public function test_an_order_from_the_app_is_born_awaiting_review(): void
    {
        // Arrange
        $me = $this->customer();
        $product = $this->product();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($product));

        // Assert
        $response->assertCreated()->assertJsonPath('data.stage', 'under_review');

        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'customer_id' => $me->id,
            'status' => OrderStatus::Requested->value,
        ]);
    }

    /**
     * Nobody on the staff made this order, and the row says so rather than borrowing a name.
     */
    public function test_an_order_from_the_app_names_no_member_of_staff(): void
    {
        // Arrange
        $me = $this->customer();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->product()));

        // Assert
        $response->assertCreated();
        $this->assertDatabaseHas('orders', ['id' => $response->json('data.id'), 'created_by' => null]);
    }

    public function test_the_order_belongs_to_the_token_not_to_anything_in_the_body(): void
    {
        // Arrange
        $me = $this->customer();
        $someoneElse = $this->customer('0922222222');

        // Act — a customer id in the body must be ignored outright.
        $response = $this->withHeaders($this->bearerFor($me))->postJson(
            '/api/v1/client/orders',
            $this->payload($this->product(), ['customer_id' => $someoneElse->id]),
        );

        // Assert
        $response->assertCreated();
        $this->assertDatabaseHas('orders', ['id' => $response->json('data.id'), 'customer_id' => $me->id]);
    }

    // ─────────────────────── what the server prices ───────────────────────

    /**
     * The line price comes from the catalogue, so the total is the total the app showed on the
     * product screen — and a client that posted its own price would be buying at one it invented.
     */
    public function test_the_server_prices_every_line_and_ignores_a_posted_price(): void
    {
        // Arrange
        $me = $this->customer();
        $product = $this->product();
        $payload = $this->payload($product);
        $payload['items'][0]['unit_price'] = '0.001';

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson('/api/v1/client/orders', $payload);

        // Assert — 1000 × 0.500, not 1000 × 0.001.
        $response->assertCreated();
        $this->assertDatabaseHas('order_items', [
            'order_id' => $response->json('data.id'),
            'unit_price' => '0.500',
        ]);
    }

    public function test_a_discount_cannot_be_asked_for_from_the_app(): void
    {
        // Arrange
        $me = $this->customer();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson(
            '/api/v1/client/orders',
            $this->payload($this->product(), ['discount' => '500.00']),
        );

        // Assert — accepted, and the discount simply never happened.
        $response->assertCreated();
        $this->assertDatabaseHas('orders', ['id' => $response->json('data.id'), 'discount' => '0.00']);
    }

    // ─────────────────────── validation ───────────────────────

    public function test_an_order_needs_a_city_and_at_least_one_line(): void
    {
        // Act
        $response = $this->withHeaders($this->bearerFor($this->customer()))
            ->postJson('/api/v1/client/orders', ['items' => []]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors(['city_id', 'items']);
    }

    /**
     * The design comes from the customer's own library, and one belonging to somebody else takes
     * the whole order down rather than leaving an order standing without the file it was placed
     * for.
     */
    public function test_another_customers_design_cannot_be_attached(): void
    {
        // Arrange
        $me = $this->customer();
        $theirs = CustomerDesign::factory()->create([
            'customer_id' => $this->customer('0922222222')->id,
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->postJson(
            '/api/v1/client/orders',
            $this->payload($this->product(), ['design_ids' => [$theirs->id]]),
        );

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_placing_an_order_needs_a_customer_token(): void
    {
        // Act
        $response = $this->postJson('/api/v1/client/orders', []);

        // Assert
        $response->assertUnauthorized();
    }

    // ─────────────────── the vendor, deferred to acceptance ───────────────────

    /**
     * **A customer cannot name a vendor** — they do not know we outsource anything, and it is
     * not their choice. So the request is taken without one.
     */
    public function test_an_outsourced_product_can_be_ordered_without_a_vendor(): void
    {
        // Arrange
        $me = $this->customer();
        $product = $this->product(ProductionMode::Outsourced);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($product));

        // Assert
        $response->assertCreated();
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'status' => OrderStatus::Requested->value,
            'vendor_id' => null,
        ]);
    }

    /**
     * **And the road is resolved at intake anyway**, so the review screen can say «تحتاج
     * وسيطاً» rather than the reviewer finding out by being refused.
     */
    public function test_the_road_is_known_before_anybody_reviews_it(): void
    {
        // Arrange
        $me = $this->customer();
        $product = $this->product(ProductionMode::Outsourced);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($product));

        // Assert
        $response->assertCreated();
        $order = Order::query()->findOrFail($response->json('data.id'));
        $this->assertSame(OrderFlow::Outsourced, $order->production_flow);
    }

    /**
     * **The rule binds here, and only here.** Accepting is the moment a request becomes an
     * order, and an outsourced order with nobody named is one the shop cannot chase.
     */
    public function test_accepting_an_outsourced_request_is_refused_until_a_vendor_is_named(): void
    {
        // Arrange
        $me = $this->customer();
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->product(ProductionMode::Outsourced)))
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $headers = $this->staff(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            ['status' => OrderStatus::New->value],
        );

        // Assert
        $response->assertStatus(422)->assertJsonPath('status', false);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Requested->value,
        ]);
    }

    /**
     * **The vendor rides along with the acceptance**, so the reviewer answers the question in
     * the same tap that accepts rather than being refused and sent to the edit screen first.
     * `TransitionFields` offers `vendor_id` on exactly this move — the same mechanism that
     * carries a payment with «تم الاستلام» and a figure with «انتظار العربون».
     */
    public function test_the_accept_call_can_name_the_vendor_itself(): void
    {
        // Arrange
        $me = $this->customer();
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->product(ProductionMode::Outsourced)))
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $vendor = Vendor::factory()->create(['name' => 'مطبعة الصحراء']);
        $headers = $this->staff(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act — one call, not two.
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            [
                'status' => OrderStatus::New->value,
                'fields' => ['vendor_id' => $vendor->id],
            ],
        );

        // Assert
        $response->assertOk();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::New->value,
            'vendor_id' => $vendor->id,
            // Snapshotted beside the id, for the reason the city name is: renaming a workshop
            // must not rewrite who made an order last year.
            'vendor_name' => 'مطبعة الصحراء',
        ]);
    }

    /**
     * The field is only offered on the move that needs it, and the request refuses anything the
     * move did not offer — so it cannot be used to set a vendor on an ordinary printed order.
     */
    public function test_the_vendor_field_is_refused_on_a_move_that_never_offered_it(): void
    {
        // Arrange
        $me = $this->customer();
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->product()))
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $vendor = Vendor::factory()->create();
        $headers = $this->staff(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            [
                'status' => OrderStatus::New->value,
                'fields' => ['vendor_id' => $vendor->id],
            ],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('fields');
    }

    /**
     * A vendor removed from the list may not be given new work — the same answer the picker
     * gives, said by the endpoint too.
     */
    public function test_a_deleted_vendor_cannot_be_named_when_accepting(): void
    {
        // Arrange
        $me = $this->customer();
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->product(ProductionMode::Outsourced)))
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $vendor = Vendor::factory()->create();
        $vendor->delete();

        $headers = $this->staff(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            [
                'status' => OrderStatus::New->value,
                'fields' => ['vendor_id' => $vendor->id],
            ],
        );

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::Requested->value,
        ]);
    }

    public function test_accepting_succeeds_once_the_vendor_is_named(): void
    {
        // Arrange
        $me = $this->customer();
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->product(ProductionMode::Outsourced)))
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $order->forceFill(['vendor_id' => Vendor::factory()->create()->id])->save();

        $headers = $this->staff(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            ['status' => OrderStatus::New->value],
        );

        // Assert
        $response->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::New->value]);
    }

    /**
     * An ordinary printed order has no vendor to name, so acceptance is one tap.
     */
    public function test_accepting_an_ordinary_request_needs_nothing_extra(): void
    {
        // Arrange
        $me = $this->customer();
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->product()))
            ->assertCreated();

        $order = Order::query()->firstOrFail();
        $headers = $this->staff(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            ['status' => OrderStatus::New->value],
        );

        // Assert
        $response->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::New->value]);
    }
}
