<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Customer\Models\CustomerShop;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\AdditionalCostReason;
use App\Domain\Order\Enums\DesignSource;
use App\Domain\Order\Enums\OrderDesignStatus;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDesign;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Taking, reading and editing an order.
 *
 * The state machine has its own file; this one covers the endpoint — what a client may send,
 * what it may not, and the arithmetic nobody should be able to influence from outside.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderTest extends TestCase
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
     * @return array<string, string>
     */
    private function outsider(): array
    {
        return $this->auth();
    }

    /**
     * A product with one size at 1.100 from 100 up — enough to price a line against.
     */
    private function catalogue(): ProductVariant
    {
        $product = Product::factory()->create([
            'name' => 'كيس شحن',
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

        return $variant;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $variant = $this->catalogue();
        $customer = Customer::factory()->create();
        $city = City::factory()->create(['delivery_price' => '20.00']);

        return array_merge([
            'customer_id' => $customer->getKey(),
            'city_id' => $city->getKey(),
            'address_details' => 'شارع الجمهورية',
            'items' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'quantity' => '300',
            ]],
        ], $overrides);
    }

    // ───────────────────────────── taking an order ─────────────────────────────

    public function test_a_clerk_can_take_an_order(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم إنشاء الطلبية بنجاح')
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.status_label', 'جديدة');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_taking_an_order_no_longer_records_what_leaves_the_shelf(): void
    {
        // Arrange — a clerk on the phone agreeing «٣٠٠ قطعة». Nothing has been printed, nothing
        // has been weighed, and the parcel this figure would describe does not exist yet.
        $headers = $this->clerk();
        $payload = $this->payload();
        $payload['items'][0]['warehouse_quantity'] = '12.5';

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $payload);

        // Assert — taken, and the line holds no measurement: it is asked for on the way into
        // «جاهزة» instead, by whoever has the parcel and the scale.
        $response->assertCreated();
        $this->assertNull(OrderItem::query()->latest('id')->first()->warehouse_quantity);
    }

    public function test_an_order_number_is_plain_digits(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload());

        // Assert — asked for outright: no A1/P1-style prefix to spell out on the phone.
        $code = $response->json('data.code');
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $code);
        $this->assertSame((string) $response->json('data.id'), (string) $code);
    }

    public function test_the_line_is_priced_by_the_catalogue_not_by_the_client(): void
    {
        // Arrange
        $headers = $this->clerk();
        $payload = $this->payload();
        // A client trying to buy 300 bags for a tenth of the listed rate.
        $payload['items'][0]['unit_price'] = '0.001';

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $payload);

        // Assert — the catalogue's 1.100 wins, and the total is 300 × 1.100. The 20.00 of
        // delivery is stated beside it and added to nothing.
        $response->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '1.100')
            ->assertJsonPath('data.items_total', '330.00')
            ->assertJsonPath('data.delivery_price', '20.00')
            ->assertJsonPath('data.grand_total', '330.00');
    }

    public function test_the_delivery_fee_is_not_part_of_the_order_total(): void
    {
        // Arrange — a destination that costs 20 to reach, on an order of 330 of bags.
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload());

        // Assert — the fee is still stated, and the total is the bags alone. The courier
        // collects the fee from the customer at the door; it is neither our revenue nor our cost.
        $response->assertCreated()
            ->assertJsonPath('data.delivery_price', '20.00')
            ->assertJsonPath('data.items_total', '330.00')
            ->assertJsonPath('data.grand_total', '330.00');
    }

    public function test_the_destination_is_copied_onto_the_order(): void
    {
        // Arrange
        $city = City::factory()->requiringRegion()->create([
            'name' => 'طرابلس',
            'delivery_price' => '15.00',
        ]);
        $region = Region::factory()->create([
            'city_id' => $city->getKey(),
            'name' => 'سوق الجمعة',
        ]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'city_id' => $city->getKey(),
            'region_id' => $region->getKey(),
        ]));

        // Assert — the snapshot, which is what protects an old order from the map changing.
        $response->assertCreated()
            ->assertJsonPath('data.city_name', 'طرابلس')
            ->assertJsonPath('data.region_name', 'سوق الجمعة')
            ->assertJsonPath('data.delivery_price', '15.00');
    }

    public function test_renaming_a_city_does_not_rewrite_an_order_already_taken(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'طرابلس', 'delivery_price' => '15.00']);
        $headers = $this->clerk();
        $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'city_id' => $city->getKey(),
        ]))->assertCreated();

        // Act — the business renegotiates and renames.
        $city->update(['name' => 'طرابلس الكبرى', 'delivery_price' => '35.00']);

        // Assert — the order still says what it said on the day.
        $order = Order::query()->sole();
        $this->assertSame('طرابلس', $order->city_name);
        $this->assertSame('15.00', $order->delivery_price);
    }

    public function test_a_city_that_needs_a_region_refuses_an_order_without_one(): void
    {
        // Arrange
        $city = City::factory()->requiringRegion()->create(['name' => 'طرابلس']);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'city_id' => $city->getKey(),
        ]));

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('region_id');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_region_from_another_city_is_refused(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'مصراتة']);
        $elsewhere = Region::factory()->create(['city_id' => City::factory()->create()->getKey()]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'city_id' => $city->getKey(),
            'region_id' => $elsewhere->getKey(),
        ]));

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('region_id');
    }

    public function test_another_customers_shop_cannot_be_named_on_an_order(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $strangersShop = CustomerShop::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
        ]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'customer_id' => $customer->getKey(),
            'customer_shop_id' => $strangersShop->getKey(),
        ]));

        // Assert — and the other customer's record is untouched.
        $response->assertStatus(422);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_deactivated_customer_cannot_be_sold_to(): void
    {
        // Arrange — somebody the shop has stopped selling to. The app hides «طلبية جديدة» on
        // their screen; this is the half that is a rule rather than a suggestion, and it is the
        // half a deep link, a seeder or an import has to walk into as well.
        $retired = Customer::factory()->inactive()->create(['name' => 'محل النور']);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'customer_id' => $retired->getKey(),
        ]));

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'العميل «محل النور» معطَّل، ولا تُؤخذ منه طلبيات');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_active_customer_is_still_sold_to(): void
    {
        // Arrange — the other side of the guard above, so a refusal that fires on everybody
        // cannot pass as a working rule.
        $customer = Customer::factory()->create();
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'customer_id' => $customer->getKey(),
        ]));

        // Assert
        $response->assertCreated();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_the_fulfilment_type_comes_from_the_city(): void
    {
        // Arrange
        $branch = City::factory()->officePickup()->create();
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'city_id' => $branch->getKey(),
        ]));

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.fulfilment_type', 'office_pickup')
            ->assertJsonPath('data.is_office_pickup', true)
            ->assertJsonPath('data.delivery_price', '0.00');
    }

    public function test_an_order_needs_at_least_one_line(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'items' => [],
        ]));

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_a_quote_only_product_needs_a_price_from_a_person(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'name' => 'كيس ورقي 3D',
            'pricing_mode' => PricingMode::QuoteOnRequest,
            'min_order_quantity' => '1',
        ]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'items' => [[
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => '50',
            ]],
        ]));

        // Assert — refused rather than guessed at, which is what the catalogue promises.
        $response->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_a_quote_only_product_is_orderable_once_a_price_is_named(): void
    {
        // Arrange
        $product = Product::factory()->create([
            'name' => 'كيس ورقي 3D',
            'pricing_mode' => PricingMode::QuoteOnRequest,
            'min_order_quantity' => '1',
        ]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'items' => [[
                'product_id' => $product->getKey(),
                'product_variant_id' => $variant->getKey(),
                'quantity' => '50',
                'unit_price' => '2.500',
            ]],
        ]));

        // Assert — 50 × 2.500 = 125.00, and the delivery is beside it rather than in it.
        $response->assertCreated()
            ->assertJsonPath('data.items_total', '125.00')
            ->assertJsonPath('data.grand_total', '125.00');
    }

    public function test_the_shops_name_is_copied_onto_the_order(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $shop = CustomerShop::factory()->create([
            'customer_id' => $customer->getKey(),
            'name' => 'فرع شارع الجمهورية',
        ]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'customer_id' => $customer->getKey(),
            'customer_shop_id' => $shop->getKey(),
        ]));

        // Assert — the same snapshot rule the city and the region already follow.
        $response->assertCreated()
            ->assertJsonPath('data.customer_shop_name', 'فرع شارع الجمهورية');
    }

    public function test_renaming_a_shop_does_not_rewrite_an_order_already_taken(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $shop = CustomerShop::factory()->create([
            'customer_id' => $customer->getKey(),
            'name' => 'فرع شارع الجمهورية',
        ]);
        $headers = $this->clerk();
        $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'customer_id' => $customer->getKey(),
            'customer_shop_id' => $shop->getKey(),
        ]))->assertCreated();

        // Act — the customer moves the branch and renames it.
        $shop->update(['name' => 'فرع سوق الجمعة']);

        // Assert — the order still says where it was going on the day.
        $this->assertSame('فرع شارع الجمهورية', Order::query()->sole()->customer_shop_name);
    }

    public function test_an_order_with_no_shop_carries_no_shop_name(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload());

        // Assert — plenty of customers have one place; null is the honest answer, not ''.
        $response->assertCreated()->assertJsonPath('data.customer_shop_name', null);
    }

    // ───────────────────────────────── the discount ─────────────────────────────────

    public function test_a_discount_needs_its_own_permission(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'discount' => '50.00',
        ]));

        // Assert — the field being hidden in the app is a suggestion; this is the rule.
        $response->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_clerk_with_the_grant_may_discount(): void
    {
        // Arrange
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::DiscountOrders,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'discount' => '50.00',
        ]));

        // Assert — 330.00 + 20.00 − 50.00.
        $response->assertCreated()
            ->assertJsonPath('data.discount', '50.00')
            ->assertJsonPath('data.grand_total', '280.00');
    }

    public function test_a_discount_larger_than_the_order_is_refused(): void
    {
        // Arrange
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::DiscountOrders,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'discount' => '5000.00',
        ]));

        // Assert — a negative total is a refund, and this system has no concept of one.
        $response->assertStatus(422)->assertJsonValidationErrors('discount');
    }

    // ─────────────────────────── the additional cost ───────────────────────────

    public function test_an_additional_cost_needs_its_own_permission(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'additional_cost' => '10.00',
            'additional_cost_reason' => AdditionalCostReason::SpecialPackaging->value,
        ]));

        // Assert — its own grant rather than the discount's: one gives money away and the other
        // asks for more, and a role may reasonably be trusted with exactly one of them.
        $response->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_clerk_with_the_grant_may_add_an_additional_cost(): void
    {
        // Arrange
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::AddOrderAdditionalCost,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'additional_cost' => '10.00',
            'additional_cost_reason' => AdditionalCostReason::SpecialPackaging->value,
            'additional_cost_note' => 'علبة كرتون مزدوجة',
        ]));

        // Assert — 330.00 + 10.00, and the reason travels with its label.
        $response->assertCreated()
            ->assertJsonPath('data.additional_cost', '10.00')
            ->assertJsonPath('data.additional_cost_reason', 'special_packaging')
            ->assertJsonPath('data.additional_cost_reason_label', 'تغليف خاص')
            ->assertJsonPath('data.additional_cost_note', 'علبة كرتون مزدوجة')
            ->assertJsonPath('data.grand_total', '340.00');
    }

    public function test_the_worked_example_from_the_brief(): void
    {
        // Arrange — «قيمة المنتجات ١٢٠ · التكلفة الإضافية ١٠ · إجمالي الطلب ١٣٠», priced to the
        // dinar so the arithmetic in the brief is the arithmetic here: 300 × 0.400, collected in
        // person so nothing else joins the sum.
        $product = Product::factory()->create([
            'pricing_mode' => PricingMode::Tiered,
            'min_order_quantity' => '100',
        ]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        ProductPriceTier::factory()->create([
            'product_variant_id' => $variant->getKey(),
            'min_quantity' => '100',
            'unit_price' => '0.400',
        ]);

        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::AddOrderAdditionalCost,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', [
            'customer_id' => Customer::factory()->create()->getKey(),
            'city_id' => City::factory()->officePickup()->create()->getKey(),
            'items' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'quantity' => '300',
            ]],
            'additional_cost' => '10.00',
            'additional_cost_reason' => AdditionalCostReason::Transport->value,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.items_total', '120.00')
            ->assertJsonPath('data.additional_cost', '10.00')
            ->assertJsonPath('data.grand_total', '130.00');
    }

    public function test_the_additional_cost_and_the_discount_stay_two_separate_figures(): void
    {
        // Arrange
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::DiscountOrders,
            PermissionName::AddOrderAdditionalCost,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'additional_cost' => '10.00',
            'additional_cost_reason' => AdditionalCostReason::Modification->value,
            'discount' => '50.00',
        ]));

        // Assert — 330.00 + 10.00 − 50.00, and neither figure folded into the other:
        // reading why a total moved needs both halves standing on their own.
        $response->assertCreated()
            ->assertJsonPath('data.additional_cost', '10.00')
            ->assertJsonPath('data.discount', '50.00')
            ->assertJsonPath('data.grand_total', '290.00');
    }

    public function test_a_discount_may_reach_the_widened_base(): void
    {
        // Arrange — the ceiling on a discount is what the customer would otherwise pay, so an
        // additional cost raises it by its own amount.
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::DiscountOrders,
            PermissionName::AddOrderAdditionalCost,
        );

        // Act — 330.00 + 10.00, taken off in full.
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'additional_cost' => '10.00',
            'additional_cost_reason' => AdditionalCostReason::ExtraService->value,
            'discount' => '340.00',
        ]));

        // Assert
        $response->assertCreated()->assertJsonPath('data.grand_total', '0.00');
    }

    public function test_an_additional_cost_without_a_reason_is_refused(): void
    {
        // Arrange
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::AddOrderAdditionalCost,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'additional_cost' => '10.00',
        ]));

        // Assert — the reason is the axis this figure will be read along later, and a charge
        // nobody can account for is what the field exists to prevent.
        $response->assertStatus(422)->assertJsonValidationErrors('additional_cost_reason');
    }

    public function test_the_reason_other_needs_words_of_its_own(): void
    {
        // Arrange
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::AddOrderAdditionalCost,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'additional_cost' => '10.00',
            'additional_cost_reason' => AdditionalCostReason::Other->value,
        ]));

        // Assert — «أخرى» on its own carries no information at all.
        $response->assertStatus(422)->assertJsonValidationErrors('additional_cost_note');
    }

    public function test_a_reason_outside_the_list_is_refused(): void
    {
        // Arrange
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::AddOrderAdditionalCost,
        );

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'additional_cost' => '10.00',
            'additional_cost_reason' => 'shipping_insurance',
        ]));

        // Assert — a closed set, so the column stays something a report can group by.
        $response->assertStatus(422)->assertJsonValidationErrors('additional_cost_reason');
    }

    public function test_a_negative_additional_cost_is_refused(): void
    {
        // Arrange
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::AddOrderAdditionalCost,
        );

        // Act — a discount wearing the wrong field's name.
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'additional_cost' => '-10.00',
            'additional_cost_reason' => AdditionalCostReason::Transport->value,
        ]));

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('additional_cost');
    }

    public function test_changing_the_additional_cost_needs_the_grant(): void
    {
        // Arrange — an order already carrying a charge. Built by the factory rather than taken
        // through the endpoint by a second user: one test process authenticates once, so two
        // requests under two tokens would both run as whoever signed in first.
        $order = Order::factory()->create([
            'additional_cost' => '10.00',
            'additional_cost_reason' => AdditionalCostReason::Transport,
        ]);

        // Act — a clerk without the grant raising the charge.
        $response = $this->withHeaders($this->clerk())->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'additional_cost' => '90.00',
            'additional_cost_reason' => AdditionalCostReason::Transport->value,
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertSame('10.00', (string) $order->fresh()->additional_cost);
    }

    public function test_an_edit_that_leaves_the_additional_cost_alone_is_not_refused(): void
    {
        // Arrange — see the test above for why the order is built rather than posted.
        $order = Order::factory()->create([
            'additional_cost' => '10.00',
            'additional_cost_reason' => AdditionalCostReason::Transport,
        ]);

        // Act — every edit re-sends the whole order, so the figure comes back untouched beside
        // the note somebody actually changed.
        $response = $this->withHeaders($this->clerk())->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'notes' => 'العميل يريدها قبل الخميس',
            'additional_cost' => '10.00',
            'additional_cost_reason' => AdditionalCostReason::Transport->value,
        ]);

        // Assert — refusing this would make an order carrying a charge uneditable by everyone
        // else, over a number nobody touched.
        $response->assertOk()->assertJsonPath('data.notes', 'العميل يريدها قبل الخميس');
    }

    public function test_the_charge_can_be_taken_back_off(): void
    {
        // Arrange
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::ManageOrders,
            PermissionName::AddOrderAdditionalCost,
        );
        $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'additional_cost' => '10.00',
            'additional_cost_reason' => AdditionalCostReason::Transport->value,
        ]));
        $order = Order::query()->sole();

        // Act — the amount cleared, the reason left where it was: what the form sends when
        // somebody empties the box without touching the chips.
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'additional_cost' => '0',
            'additional_cost_reason' => AdditionalCostReason::Transport->value,
        ]);

        // Assert — nothing is charged, and the sum goes back to the bags' own 330.00.
        $response->assertOk()
            ->assertJsonPath('data.additional_cost', '0.00')
            ->assertJsonPath('data.grand_total', '330.00');
    }

    public function test_a_design_fee_is_only_charged_when_we_did_the_design(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act — the fee is sent, but the customer supplied the artwork.
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'design_source' => DesignSource::Customer->value,
            'design_fee' => '80.00',
        ]));

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.design_fee', '0.00')
            ->assertJsonPath('data.grand_total', '330.00');
    }

    public function test_our_own_design_is_added_to_the_total(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'design_source' => DesignSource::InHouse->value,
            'design_fee' => '80.00',
        ]));

        // Assert — 330.00 + 80.00.
        $response->assertCreated()
            ->assertJsonPath('data.design_fee', '80.00')
            ->assertJsonPath('data.grand_total', '410.00');
    }

    /**
     * The customer walked in with the finished file.
     *
     * **The commonest order in the shop, and the one the old rule had no room for.** Artwork
     * could only be attached in «قيد التصميم», so recording a file that was agreed before the
     * order existed meant sending the order to the designer's queue and pulling it straight back
     * out — a status saying work was being done that nobody was doing, and two moves on the
     * timeline standing for nothing that happened.
     */
    public function test_an_order_may_be_taken_with_its_artwork_already_chosen(): void
    {
        // Arrange — the design is already in the customer's library, from a previous job.
        $headers = $this->clerk();
        $customer = Customer::factory()->create();
        $design = CustomerDesign::factory()->create(['customer_id' => $customer->getKey()]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'customer_id' => $customer->getKey(),
            'design_source' => DesignSource::Customer->value,
            'design_ids' => [$design->getKey()],
        ]));

        // Assert — attached, and the order is still «جديدة»: nothing was designed, so nothing
        // pretends to have been.
        $response->assertCreated()->assertJsonPath('data.status', 'new');

        $this->assertDatabaseHas('order_designs', [
            'order_id' => $response->json('data.id'),
            'customer_design_id' => $design->getKey(),
            'version' => 1,
            'status' => OrderDesignStatus::Proposed->value,
        ]);
    }

    public function test_the_versions_are_numbered_in_the_order_they_were_sent(): void
    {
        // Arrange — two files for one job: the logo and the back of the bag.
        $headers = $this->clerk();
        $customer = Customer::factory()->create();
        $first = CustomerDesign::factory()->create(['customer_id' => $customer->getKey()]);
        $second = CustomerDesign::factory()->create(['customer_id' => $customer->getKey()]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'customer_id' => $customer->getKey(),
            'design_source' => DesignSource::Customer->value,
            'design_ids' => [$first->getKey(), $second->getKey()],
        ]));

        // Assert — «التصميم الأول» means the one the clerk picked first.
        $response->assertCreated();

        // `reorder`, because the relation lists the newest version first — see `Order::designs()`.
        $versions = Order::query()
            ->findOrFail($response->json('data.id'))
            ->designs()
            ->reorder('version')
            ->pluck('customer_design_id')
            ->all();

        $this->assertSame([$first->getKey(), $second->getKey()], $versions);
    }

    public function test_another_customers_artwork_refuses_the_whole_order(): void
    {
        // Arrange
        $headers = $this->clerk();
        $customer = Customer::factory()->create();
        $strangers = CustomerDesign::factory()->create([
            'customer_id' => Customer::factory()->create()->getKey(),
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'customer_id' => $customer->getKey(),
            'design_ids' => [$strangers->getKey()],
        ]));

        // Assert — one transaction. The alternative is an order that exists, has a number, and
        // is missing the only file it was taken for.
        $response->assertStatus(422)->assertJsonValidationErrors('customer_design_id');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_design_that_does_not_exist_is_refused_before_anything_is_written(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'design_ids' => [999_999],
        ]));

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('design_ids.0');
        $this->assertDatabaseCount('orders', 0);
    }

    // ─────────────────────────────── invariants ───────────────────────────────

    public function test_a_client_cannot_dictate_the_total(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload([
            'grand_total' => '1.00',
            'items_total' => '1.00',
            'code' => 'FREE',
            'status' => OrderStatus::Delivered->value,
        ]));

        // Assert — every one of those is server-assigned and none of them lands.
        $response->assertCreated()
            ->assertJsonPath('data.grand_total', '330.00')
            ->assertJsonPath('data.status', 'new');

        $this->assertNotSame('FREE', $response->json('data.code'));
    }

    public function test_taking_an_order_opens_its_timeline(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload())->assertCreated();

        // Assert — `from` is null exactly once per order, which is what makes "when was this
        // taken" a query on the timeline rather than a special case elsewhere.
        $this->assertDatabaseHas('order_status_transitions', [
            'from_status' => null,
            'to_status' => OrderStatus::New->value,
        ]);
    }

    // ────────────────────────────── reading them ──────────────────────────────

    public function test_a_viewer_can_list_orders(): void
    {
        // Arrange
        Order::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [['id', 'code', 'status', 'status_label', 'grand_total', 'available_transitions']],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ]);
    }

    public function test_a_page_of_orders_reads_their_lines_in_one_query(): void
    {
        // Arrange — several orders, and lines on one of them. **More than one deliberately:**
        // Eloquent arms its lazy-loading guard only for a query that returned several rows,
        // which is exactly the shape a page has and a single-row fixture does not — so a list
        // test with one order proves nothing about the list.
        $withLines = Order::factory()->create();
        OrderItem::factory()->for($withLines)->create(['variant_label' => '30*30']);
        Order::factory()->create();
        // Someone who may actually record a shortage: the fields of a move are only described
        // for the moves this user is offered.
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::MoveOrderToShortage);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert — every new order is offered «نواقص», and that offer is a field per line,
        // so the list cannot render without the lines. Loaded with the page rather than fetched
        // per row: a query per order is what turns a work queue into a slow screen.
        $shortage = collect($response->assertOk()->json('data.1.available_transitions'))
            ->firstWhere('status', OrderStatus::Shortage->value);

        $this->assertNotNull($shortage);
        $this->assertSame(
            ['shortage_'.$withLines->items()->value('id'), 'reason'],
            array_column($shortage['fields'], 'key'),
        );
    }

    public function test_a_page_of_orders_carries_the_product_behind_every_line(): void
    {
        // Arrange — the card in the list shows what is in the order, with its picture. Several
        // orders deliberately: Eloquent arms its lazy-loading guard only on a multi-row result,
        // so a one-order fixture proves nothing about a page.
        foreach (range(1, 2) as $index) {
            $order = Order::factory()->create();
            $product = Product::factory()->create();
            ProductImage::factory()->primary()->for($product)->create();
            OrderItem::factory()->for($order)->create(['product_id' => $product->getKey()]);
        }

        $headers = $this->viewer();

        // Act — a lazy load anywhere in here throws under `preventLazyLoading`.
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert
        $response->assertOk();
        $this->assertNotNull($response->json('data.0.items.0.product_code'));
        $this->assertNotEmpty($response->json('data.0.items.0.product_image.url'));
    }

    /**
     * The card in the list shows what is printed on the order, not the catalogue's photograph.
     *
     * **The one thing that tells two orders apart at a glance.** Every line of every order was
     * drawing the same white bag, and the file that says whose order this is was already on the
     * detail payload and missing from the list. Loaded with the page, never per row: a page of
     * twenty that fetches its artwork one order at a time is the query per row this list exists
     * to avoid — several orders here deliberately, because Eloquent arms `preventLazyLoading`
     * only on a multi-row result.
     */
    public function test_a_page_of_orders_carries_the_artwork_printed_on_them(): void
    {
        // Arrange — two orders, and one of them carries two files: the logo and the back of the
        // bag are two designs on one job, and the card puts them side by side.
        $customer = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->getKey()]);

        foreach (range(1, 2) as $index) {
            OrderDesign::factory()->for($order)->create([
                'customer_design_id' => CustomerDesign::factory()->create([
                    'customer_id' => $customer->getKey(),
                ]),
            ]);
        }

        $bare = Order::factory()->create();
        $headers = $this->viewer();

        // Act — a lazy load anywhere in here throws under `preventLazyLoading`.
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert — the rows are found by id rather than by position: the page is sorted by
        // `placed_at`, which these two fixtures share.
        $rows = collect($response->assertOk()->json('data'))->keyBy('id');

        // Both versions, each with the signed link the card draws from.
        $this->assertCount(2, $rows[$order->getKey()]['designs']);
        $this->assertNotEmpty($rows[$order->getKey()]['designs'][0]['design']['file_url']);
        $this->assertSame('image', $rows[$order->getKey()]['designs'][0]['design']['kind']);
        // And the order with no artwork says so with an empty list rather than a missing key.
        $this->assertSame([], $rows[$bare->getKey()]['designs']);
    }

    public function test_orders_can_be_filtered_by_status(): void
    {
        // Arrange
        Order::factory()->status(OrderStatus::Printing)->create();
        Order::factory()->status(OrderStatus::Delivered)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders?status=printing');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'printing');
    }

    public function test_several_statuses_can_be_asked_for_at_once(): void
    {
        // Arrange
        Order::factory()->status(OrderStatus::Printing)->create();
        Order::factory()->status(OrderStatus::Ready)->create();
        Order::factory()->status(OrderStatus::Delivered)->create();
        $headers = $this->viewer();

        // Act — the work queues staff want are groups, not single statuses.
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/orders?status[]=printing&status[]=ready');

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_an_order_can_be_found_by_its_number(): void
    {
        // Arrange
        $order = Order::factory()->create();
        Order::factory()->count(2)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders?search={$order->code}");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $order->code);
    }

    public function test_one_order_carries_its_lines_and_its_timeline(): void
    {
        // Arrange
        $headers = $this->clerk();
        $created = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload());
        $id = $created->json('data.id');

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$id}");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonCount(1, 'data.transitions')
            ->assertJsonPath('data.transitions.0.to_status', 'new');
    }

    public function test_a_line_carries_the_catalogue_row_behind_it(): void
    {
        // Arrange — the snapshot on a line is a name and a size, which is what an old invoice
        // must keep saying. The card that opens the product needs the live row too: the code
        // people say out loud, and the one photograph.
        $order = Order::factory()->create();
        $product = Product::factory()->create(['name' => 'كيس شحن']);
        ProductImage::factory()->primary()->for($product)->create();
        ProductImage::factory()->for($product)->create(['sort_order' => 1]);
        OrderItem::factory()->for($order)->create(['product_id' => $product->getKey()]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->getKey()}");

        // Assert — the code, and the primary image alone. A line is a row in a list, and the
        // whole gallery is the product screen's job.
        $response->assertOk()
            ->assertJsonPath('data.items.0.product_code', $product->code)
            ->assertJsonPath('data.items.0.product_image.is_primary', true);

        $this->assertNotEmpty($response->json('data.items.0.product_image.url'));
    }

    public function test_a_product_with_no_photograph_leaves_the_slot_empty(): void
    {
        // Arrange — most of the catalogue, most of the time.
        $order = Order::factory()->create();
        $product = Product::factory()->create();
        OrderItem::factory()->for($order)->create(['product_id' => $product->getKey()]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->getKey()}");

        // Assert — null rather than absent, so the client has one thing to check.
        $response->assertOk()->assertJsonPath('data.items.0.product_image', null);
    }

    public function test_the_lines_of_one_order_read_their_products_in_one_query(): void
    {
        // Arrange — several lines, each on its own product: a query per line is what turns a
        // four-line order into a slow screen, and the guard only arms on a multi-row result.
        $order = Order::factory()->create();

        foreach (range(1, 3) as $index) {
            $product = Product::factory()->create();
            ProductImage::factory()->primary()->for($product)->create();
            OrderItem::factory()->for($order)->create(['product_id' => $product->getKey()]);
        }

        $headers = $this->viewer();

        // Act & Assert — a lazy load anywhere in here throws under `preventLazyLoading`.
        $this->withHeaders($headers)
            ->getJson("/api/v1/orders/{$order->getKey()}")
            ->assertOk()
            ->assertJsonCount(3, 'data.items');
    }

    public function test_a_missing_order_is_a_404(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders/999999');

        // Assert
        $response->assertNotFound();
    }

    // ────────────────────────────── access ──────────────────────────────

    public function test_listing_orders_needs_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/orders');

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_user_granted_nothing_may_not_list_orders(): void
    {
        // Arrange
        Order::factory()->create();
        $headers = $this->outsider();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/orders');

        // Assert
        $response->assertForbidden();
    }

    public function test_a_viewer_may_not_take_an_order(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload());

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    // ────────────────────────────── editing ──────────────────────────────

    public function test_a_clerk_can_change_the_notes_on_an_open_order(): void
    {
        // Arrange
        $headers = $this->clerk();
        $created = $this->withHeaders($headers)->postJson('/api/v1/orders', $this->payload());
        $order = Order::query()->sole();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'notes' => 'العميل يريدها قبل الخميس',
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.notes', 'العميل يريدها قبل الخميس');
        // Omitting `items` leaves the lines alone.
        $this->assertSame(1, $order->items()->count());
    }

    public function test_a_run_being_printed_can_still_have_its_quantity_corrected(): void
    {
        // Arrange — the press is running; the quantity is exactly what is still being decided.
        $variant = $this->catalogue();
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'items' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'quantity' => '300',
            ]],
        ]);

        // Assert
        $response->assertOk();
        $this->assertSame('300.000', $order->fresh()->items()->value('quantity'));
    }

    public function test_the_lines_close_once_the_bags_are_on_the_shelf(): void
    {
        // Arrange
        $variant = $this->catalogue();
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'items' => [[
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'quantity' => '300',
            ]],
        ]);

        // Assert — the bags exist by now; an order disagreeing with the shop floor is a wrong
        // invoice, not a correction.
        $response->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_the_destination_freezes_once_the_parcel_is_on_the_road(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::OutForDelivery)->create();
        $elsewhere = City::factory()->create();
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $elsewhere->getKey(),
        ]);

        // Assert — the address on our screen and the one on the label have already parted
        // company, and only the label is real.
        $response->assertStatus(422)->assertJsonValidationErrors('city_id');
    }

    public function test_the_recipient_phone_freezes_with_the_destination(): void
    {
        // Arrange — the number the courier is calling is as much «where this is going» as the
        // city is, and it is already on their screen.
        $order = Order::factory()->status(OrderStatus::OutForDelivery)->create([
            'recipient_phone' => '0913334444',
        ]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'recipient_phone' => '0925556666',
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('recipient_phone');
        $this->assertSame('0913334444', $order->refresh()->recipient_phone);
    }

    public function test_an_order_on_the_road_can_still_be_edited_without_touching_the_phone(): void
    {
        // Arrange — every edit re-sends the whole order, so the guard has to fire on a *change*
        // and not on the number coming back unchanged. Without this the notes on a parcel in
        // delivery could never be corrected.
        $order = Order::factory()->status(OrderStatus::OutForDelivery)->create([
            'recipient_phone' => '0913334444',
        ]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'recipient_phone' => '0913334444',
            'notes' => 'يتصل قبل الوصول بساعة',
        ]);

        // Assert
        $response->assertOk();
        $this->assertSame('يتصل قبل الوصول بساعة', $order->refresh()->notes);
    }

    public function test_the_recipient_phone_can_be_corrected_before_the_parcel_leaves(): void
    {
        // Arrange — the other side of the guard: a wrong number is worth fixing right up until
        // somebody is driving to it.
        $order = Order::factory()->status(OrderStatus::Ready)->create([
            'recipient_phone' => '0913334444',
        ]);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'recipient_phone' => '0925556666',
        ]);

        // Assert
        $response->assertOk();
        $this->assertSame('0925556666', $order->refresh()->recipient_phone);
    }

    public function test_a_parcel_on_its_way_back_can_still_be_readdressed(): void
    {
        // Arrange — with the courier, coming home.
        $order = Order::factory()->status(OrderStatus::ReturnedCourier)->create();
        $elsewhere = City::factory()->create(['name' => 'الزاوية', 'delivery_price' => '35.00']);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $elsewhere->getKey(),
        ]);

        // Assert — «ابعثها للفرع الثاني بدل ما ترجع» is said about a parcel in exactly this
        // state, and the new address has to be on the order *before* it goes out again.
        $response->assertOk();

        $moved = $order->fresh();
        $this->assertSame($elsewhere->getKey(), $moved->city_id);
        $this->assertSame('الزاوية', $moved->city_name);
        $this->assertSame('35.00', $moved->delivery_price);
    }

    public function test_moving_an_order_re_prices_the_delivery_and_leaves_the_total_alone(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        OrderItem::factory()->for($order)->create([
            'quantity' => '10',
            'unit_price' => '10.00',
            'line_total' => '100.00',
        ]);
        $dearer = City::factory()->create(['delivery_price' => '50.00']);
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $dearer->getKey(),
        ]);

        // Assert — the rate travels with the address, and the total does not move with it: the
        // goods cost what they cost wherever they are going, and the trip is the courier's bill.
        $response->assertOk()
            ->assertJsonPath('data.delivery_price', '50.00')
            ->assertJsonPath('data.grand_total', '100.00');
    }

    public function test_a_finished_order_cannot_be_edited(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Delivered)->create();
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/orders/{$order->id}", [
            'city_id' => $order->city_id,
            'notes' => 'محاولة تعديل',
        ]);

        // Assert
        $response->assertStatus(422);
        $this->assertNull($order->fresh()->notes);
    }

    // ────────────────────────────── the history ──────────────────────────────

    public function test_an_order_has_a_history_endpoint(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->auth(PermissionName::ViewOrders, PermissionName::ViewActivityLogs);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}/logs");

        // Assert
        $response->assertOk()->assertJsonPath('status', true);
    }

    public function test_reading_an_order_history_needs_the_log_permission(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->clerk();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}/logs");

        // Assert — reading a history surfaces what everyone has done, which is a different
        // decision from reading the record.
        $response->assertForbidden();
    }
}
