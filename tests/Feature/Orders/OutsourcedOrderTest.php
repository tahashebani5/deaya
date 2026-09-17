<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Order\Enums\OrderFlow;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Vendor\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * وسيط — an order دعاية sells and an outside vendor makes.
 *
 * `OrderStatusTest` pins the road as a map and `OrderProductionFlowTest` proves an order acquires
 * it from its lines. This file is the business's own acceptance list, walked end to end through
 * the API: the cost is set on the product and copied onto the order, the vendor is chosen from
 * the list, the order goes جديدة → قيد التصنيع → جاهزة, no shelf of ours is touched on the way,
 * and the clerk taking the order never sees a cost at all.
 *
 * The worked example throughout is the requirement's own: 50 كرت بزنس, sold at 50, bought at 25.
 *
 * Arrange - Act - Assert throughout.
 */
class OutsourcedOrderTest extends TestCase
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

    /** Someone who may take an order and walk it down the وسيط road. */
    private function coordinator(): array
    {
        return $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::ManageOrderDesigns,
            PermissionName::MoveOrderToDesigning,
            PermissionName::MoveOrderToManufacturing,
            PermissionName::MoveOrderToReady,
            PermissionName::CancelOrders,
            // What the shop pays the vendor. Held here so the assertions below can read the
            // costs back; the clerk in `test_the_clerk_taking_the_order_is_never_shown_a_cost`
            // deliberately does not have it.
            PermissionName::ViewProductCost,
        );
    }

    /** 50 كرت بزنس: sold at 50, and bought from a vendor at [$cost]. */
    private function businessCards(?string $cost = '25.000'): ProductVariant
    {
        $product = Product::factory()->create([
            'name' => 'كرت بزنس',
            'product_category_id' => ProductCategory::factory()->outsourced()->create(['name' => 'وسيط'])->id,
            'pricing_unit' => PricingUnit::Piece,
            'pricing_mode' => PricingMode::Tiered,
            'min_order_quantity' => '50',
        ]);

        // **No `stock_item_id`, and that is the point of the road.** A وسيط size sits on no shelf
        // of ours; a fixture that gave it one would be testing a product this feature does not
        // describe, and would hide the very thing `deductsStock()` exists to prevent.
        $variant = ProductVariant::factory()->for($product)->create([
            'label' => '9*5',
            'cost_price' => $cost,
            'stock_item_id' => null,
        ]);

        ProductPriceTier::factory()->create([
            'product_variant_id' => $variant->getKey(),
            'min_quantity' => '50',
            'unit_price' => '1.000',
        ]);

        return $variant;
    }

    /**
     * Takes an order through the endpoint — the only path that stamps a road and snapshots a
     * vendor, which is what most of these tests are about.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $overrides
     */
    private function take(array $headers, ProductVariant $size, array $overrides = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson('/api/v1/orders', array_merge([
            'customer_id' => Customer::factory()->create()->getKey(),
            'city_id' => City::factory()->create(['delivery_price' => '20.00'])->getKey(),
            'address_details' => 'شارع الجمهورية',
            'items' => [[
                'product_id' => $size->product_id,
                'product_variant_id' => $size->getKey(),
                'quantity' => '50',
            ]],
        ], $overrides));
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $fields
     */
    private function move(array $headers, Order $order, OrderStatus $to, array $fields = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            array_filter(['status' => $to->value, 'fields' => $fields ?: null]),
        );
    }

    // ─────────────────────────── the vendor ───────────────────────────

    public function test_a_vendor_order_names_the_vendor_making_it(): void
    {
        // Arrange
        $headers = $this->coordinator();
        $vendor = Vendor::factory()->create(['name' => 'مطبعة النور']);

        // Act
        $response = $this->take($headers, $this->businessCards(), ['vendor_id' => $vendor->getKey()]);

        // Assert — the id for a screen to follow, and the name as this order said it, so a vendor
        // renamed next year does not rewrite an order taken today.
        $response->assertCreated()
            ->assertJsonPath('data.production_flow', 'outsourced')
            ->assertJsonPath('data.production_flow_label', 'مسار الوسيط')
            ->assertJsonPath('data.vendor_id', $vendor->getKey())
            ->assertJsonPath('data.vendor_name', 'مطبعة النور');
    }

    public function test_a_vendor_order_without_a_vendor_is_refused(): void
    {
        // Act — everything else in order, and nobody named to make it.
        $response = $this->take($this->coordinator(), $this->businessCards());

        // Assert — a sentence in Arabic on the field it is about, and no order left behind: the
        // refusal is thrown after the road is resolved, inside the same transaction.
        $response->assertStatus(422)->assertJsonValidationErrors('vendor_id');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_renaming_the_vendor_does_not_rewrite_an_order_already_sent(): void
    {
        // Arrange
        $headers = $this->coordinator();
        $vendor = Vendor::factory()->create(['name' => 'مطبعة النور']);
        $this->take($headers, $this->businessCards(), ['vendor_id' => $vendor->getKey()])->assertCreated();

        // Act
        $vendor->update(['name' => 'مطبعة النور الحديثة']);

        // Assert — the snapshot is what this order said at the time, exactly like `city_name`.
        $this->assertSame('مطبعة النور', Order::query()->sole()->vendor_name);
    }

    // ─────────────────────────── the cost ───────────────────────────

    public function test_the_order_keeps_a_copy_of_the_cost_as_it_stood(): void
    {
        // Arrange
        $headers = $this->coordinator();
        $size = $this->businessCards('25.000');

        // Act
        $response = $this->take($headers, $size, [
            'vendor_id' => Vendor::factory()->create()->getKey(),
        ]);

        // Assert — the copy is taken when the order is taken, not read later off the catalogue.
        $response->assertCreated()->assertJsonPath('data.items.0.unit_cost', '25.000');
    }

    public function test_raising_the_cost_later_leaves_earlier_orders_alone(): void
    {
        // Arrange — an order taken while the vendor charged 25.
        $headers = $this->coordinator();
        $size = $this->businessCards('25.000');
        $this->take($headers, $size, ['vendor_id' => Vendor::factory()->create()->getKey()])->assertCreated();

        // Act — the vendor puts his price up, and the catalogue is updated.
        $size->update(['cost_price' => '40.000']);

        // Assert — **the acceptance criterion this whole snapshot exists for.** The order taken
        // last month still says what it actually cost.
        $this->assertSame('25.000', (string) Order::query()->sole()->items()->sole()->unit_cost);
    }

    public function test_the_cost_becomes_the_orders_cogs_when_the_vendor_hands_it_over(): void
    {
        // Arrange — 50 cards at 25 each.
        $headers = $this->coordinator();
        $order = $this->takenOrder($headers);

        // Act — out to the vendor, then back finished.
        $this->move($headers, $order, OrderStatus::Manufacturing)->assertOk();
        $this->move($headers, $order, OrderStatus::Ready)->assertOk();

        // Assert — recognised at «جاهزة», the same moment a printed order is costed, and summed
        // onto the order by the one action that writes `total_cogs`.
        $item = $order->fresh()->items()->sole();
        $this->assertSame('1250.00', (string) $item->outsourcing_cost);
        $this->assertSame('1250.00', (string) $item->cogs);
        $this->assertSame('1250.00', (string) $order->fresh()->total_cogs);
    }

    public function test_a_vendor_order_carries_no_cost_before_the_goods_exist(): void
    {
        // Arrange
        $headers = $this->coordinator();
        $order = $this->takenOrder($headers);

        // Act — sent out, and not yet finished.
        $this->move($headers, $order, OrderStatus::Manufacturing)->assertOk();

        // Assert — the price agreed with a vendor is not a cost incurred: an order written off on
        // his bench never cost us anything, and a zero here would say it did.
        $this->assertNull($order->fresh()->items()->sole()->outsourcing_cost);
        $this->assertNull($order->fresh()->total_cogs);
    }

    public function test_the_clerk_taking_the_order_is_never_shown_a_cost(): void
    {
        // Arrange — an order with a cost already on its line, built from factories rather than
        // taken through the endpoint: this test is about who reads it, and a create call would
        // resolve a coordinator into the auth guard that the read below would then reuse.
        $order = Order::factory()->create();
        $size = $this->businessCards();
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'product_name' => 'كرت بزنس',
            'variant_label' => $size->label,
            'pricing_unit' => PricingUnit::Piece,
            'quantity' => '50',
        ])->forceFill(['unit_cost' => '25.000'])->save();

        $clerk = $this->auth(PermissionName::ViewOrders, PermissionName::ManageOrders);

        // Act
        $response = $this->withHeaders($clerk)->getJson("/api/v1/orders/{$order->id}");

        // Assert — **absent, not null.** Null would say «هذا البند بلا تكلفة», a claim about the
        // order; the truth is «لست ممن يرونها», a fact about the reader.
        $response->assertOk()
            ->assertJsonMissingPath('data.items.0.unit_cost')
            ->assertJsonMissingPath('data.items.0.outsourcing_cost');
    }

    // ─────────────────────────── the road ───────────────────────────

    public function test_the_order_goes_from_intake_straight_to_the_vendor(): void
    {
        // Arrange
        $headers = $this->coordinator();
        $order = $this->takenOrder($headers);

        // Act
        $response = $this->move($headers, $order, OrderStatus::Manufacturing);

        // Assert — «يمكن الانتقال من جديدة مباشرة إلى قيد التصنيع», and the moment it left is
        // stamped so «كم يوماً قعدت عند المورد؟» is answerable later.
        $response->assertOk()->assertJsonPath('data.status', 'manufacturing');
        $this->assertNotNull($order->fresh()->manufacturing_started_at);
    }

    public function test_the_order_may_be_designed_before_it_is_sent_out(): void
    {
        // Arrange
        $headers = $this->coordinator();
        $order = $this->takenOrder($headers);

        // Act — «إذا كان الطلب يحتاج تصميماً»: the long way round the same road.
        $this->move($headers, $order, OrderStatus::Designing)->assertOk();
        $this->move($headers, $order, OrderStatus::Manufacturing)->assertOk();
        $response = $this->move($headers, $order, OrderStatus::Ready);

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'ready');
    }

    public function test_the_order_is_never_offered_a_press_of_ours(): void
    {
        // Arrange
        $headers = $this->coordinator();
        $order = $this->takenOrder($headers);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — «عدم إضافة حالة جاهز للتصنيع»، ولا «قيد الطباعة»، ولا «نواقص» لبضاعة ليست
        // عندنا أصلاً. What is offered at intake is the designer or the vendor.
        $offered = array_column($response->json('data.available_transitions'), 'status');
        $this->assertEqualsCanonicalizing(['designing', 'manufacturing'], $offered);
    }

    public function test_the_move_into_ready_asks_for_no_warehouse(): void
    {
        // Arrange
        $headers = $this->coordinator();
        $order = $this->takenOrder($headers);
        $this->move($headers, $order, OrderStatus::Manufacturing)->assertOk();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");

        // Assert — nothing of ours is on a shelf for this order, so the form asks for nothing it
        // could not use. A picker here would be a question with no honest answer. The optional
        // note every move carries stays: that one is about the order, not about a warehouse.
        $ready = collect($response->json('data.available_transitions'))->firstWhere('status', 'ready');
        $this->assertSame(['reason'], array_column($ready['fields'] ?? [], 'key'));
    }

    public function test_nothing_leaves_a_shelf_of_ours(): void
    {
        // Arrange
        $headers = $this->coordinator();
        $order = $this->takenOrder($headers);

        // Act — all the way to the shelf.
        $this->move($headers, $order, OrderStatus::Manufacturing)->assertOk();
        $this->move($headers, $order, OrderStatus::Ready)->assertOk();

        // Assert — **the deduction is skipped, not merely unasked for.** A وسيط size points at no
        // stock item, so a deduction here could only fail or take the wrong thing off the wrong
        // shelf.
        $this->assertNull($order->fresh()->stock_deducted_at);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_an_order_of_goods_we_make_still_walks_its_own_road(): void
    {
        // Arrange — the control: the ordinary printed road, untouched by any of this.
        $headers = $this->coordinator();
        $printed = Product::factory()->create([
            'product_category_id' => ProductCategory::factory()->create(['name' => 'مطبوعة'])->id,
            'pricing_unit' => PricingUnit::Piece,
            'pricing_mode' => PricingMode::Tiered,
            'min_order_quantity' => '50',
        ]);
        $size = ProductVariant::factory()->for($printed)->create(['label' => '25*35']);
        ProductPriceTier::factory()->create([
            'product_variant_id' => $size->getKey(),
            'min_quantity' => '50',
            'unit_price' => '1.000',
        ]);

        // Act
        $response = $this->take($headers, $size);

        // Assert — no vendor owed, no cost copied, and the road every order walked before this
        // feature existed.
        $response->assertCreated()
            ->assertJsonPath('data.production_flow', 'standard')
            ->assertJsonPath('data.vendor_id', null)
            ->assertJsonPath('data.items.0.unit_cost', null);
    }

    public function test_the_effective_mode_reaches_a_subheading(): void
    {
        // Arrange — «وسيط» with «وسيط ورقي» added underneath it later, and the product filed on
        // the leaf, as products always are.
        $parent = ProductCategory::factory()->outsourced()->create(['name' => 'وسيط']);
        $child = ProductCategory::factory()->create(['name' => 'وسيط ورقي', 'parent_id' => $parent->getKey()]);

        $product = Product::factory()->create([
            'product_category_id' => $child->getKey(),
            'pricing_unit' => PricingUnit::Piece,
            'pricing_mode' => PricingMode::Tiered,
            'min_order_quantity' => '50',
        ]);
        $size = ProductVariant::factory()->for($product)->create(['cost_price' => '25.000', 'stock_item_id' => null]);
        ProductPriceTier::factory()->create([
            'product_variant_id' => $size->getKey(),
            'min_quantity' => '50',
            'unit_price' => '1.000',
        ]);

        // Act
        $response = $this->take($this->coordinator(), $size, [
            'vendor_id' => Vendor::factory()->create()->getKey(),
        ]);

        // Assert — the child never had to tick the box a second time. `ProductCategory` answers
        // for its children, and the flow reads the effective mode.
        $response->assertCreated()->assertJsonPath('data.production_flow', OrderFlow::Outsourced->value);
        $this->assertSame(ProductionMode::InHouse, $child->fresh()->production_mode);
    }

    /**
     * An order for 50 كرت بزنس at 25, taken through the endpoint and standing in «جديدة».
     *
     * @param  array<string, string>  $headers
     */
    private function takenOrder(array $headers): Order
    {
        $this->take($headers, $this->businessCards(), [
            'vendor_id' => Vendor::factory()->create(['name' => 'مطبعة النور'])->getKey(),
        ])->assertCreated();

        return Order::query()->sole();
    }
}
