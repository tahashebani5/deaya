<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Client;

use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\TransitionFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Ordering something the catalogue prices «حسب الطلب», from the app.
 *
 * **The whole feature is that a line may arrive with no price at all.** The customer app is
 * never *told* a price for such a product — `CatalogService::quote()` refuses to invent one —
 * so it cannot send one, and refusing the order for want of it made a whole half of the
 * catalogue unorderable from a phone. «كروت» was the report: the cart filled, the button was
 * pressed, and the server answered «سعره حسب الطلب، ويجب إدخال سعر الوحدة يدوياً».
 *
 * So the line is written null and the shop quotes it on the move that accepts the request —
 * exactly as it already names a vendor on that same move, and for the same reason: these are
 * the questions the customer could not answer.
 *
 * **Null, never zero.** The tests below that check for null are checking the thing that makes
 * this safe: a zero would be indistinguishable from a line given away free, and an order that
 * slipped past review carrying zeroes would invoice nothing with no column able to say it was
 * wrong. What keeps null from spreading is the guard at the accept — `test_a_request_cannot_be
 * _accepted_while_a_line_has_no_price` — and if that ever goes, everything else here becomes a
 * way to bill a customer less than the goods are worth.
 *
 * Arrange - Act - Assert throughout.
 */
class ClientQuoteOnRequestOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    private function customer(): Customer
    {
        return Customer::factory()->registered()->create(['phone' => '0911111111']);
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
     * A product with a size and **no price tiers at all** — the reinforced bags, the cards.
     */
    private function quotedProduct(): Product
    {
        $category = ProductCategory::factory()->create();

        $product = Product::factory()->create([
            'product_category_id' => $category->id,
            'pricing_mode' => PricingMode::QuoteOnRequest,
        ]);

        ProductVariant::factory()->create(['product_id' => $product->id]);

        return $product->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product, float $quantity = 100): array
    {
        return [
            'city_id' => City::factory()->create()->id,
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => $product->variants()->first()->id,
                'quantity' => $quantity,
            ]],
        ];
    }

    // ───────────────────────── placing it ─────────────────────────

    public function test_a_customer_can_order_a_product_priced_on_request(): void
    {
        // Arrange — the exact order that was refused: «كروت», no listed price, no price sent.
        $me = $this->customer();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->quotedProduct()));

        // Assert
        $response->assertCreated();
        $this->assertSame(OrderStatus::Requested, Order::query()->sole()->status);
    }

    public function test_the_line_is_written_with_no_price_rather_than_a_zero(): void
    {
        // Arrange
        $me = $this->customer();

        // Act
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->quotedProduct()))
            ->assertCreated();

        // Assert — **null, and this is the assertion the whole design rests on.** A zero here
        // would be a line that reads as free, and nothing downstream could tell the difference.
        $item = OrderItem::query()->sole();

        $this->assertNull($item->unit_price);
        $this->assertNull($item->line_total);
        $this->assertFalse($item->isPriced());
    }

    public function test_the_customer_is_shown_no_total_rather_than_a_wrong_one(): void
    {
        // Arrange
        $me = $this->customer();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->quotedProduct()));

        // Assert — the stored `grand_total` is 0 while nothing is priced, and sending it would
        // quote the customer a number smaller than what they will be asked to pay. Null says
        // «لا سعر بعد», which is true, and the app draws «يُحدَّد بعد المراجعة».
        $response->assertCreated()
            ->assertJsonPath('data.total', null)
            ->assertJsonPath('data.items_total', null)
            ->assertJsonPath('data.balance', null)
            ->assertJsonPath('data.is_awaiting_quote', true)
            ->assertJsonPath('data.items.0.unit_price', null)
            ->assertJsonPath('data.items.0.line_total', null);
    }

    public function test_a_clerk_is_still_refused_for_leaving_the_price_empty(): void
    {
        // Arrange — the old rule, deliberately untouched. A clerk writing an order at the
        // counter *is* the person who names the price; an empty box there is a mistake, not a
        // line awaiting a quote.
        $product = $this->quotedProduct();
        $headers = $this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/orders', [
            'customer_id' => Customer::factory()->create()->id,
            'city_id' => City::factory()->create()->id,
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => $product->variants()->first()->id,
                'quantity' => 100,
            ]],
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('items');
    }

    // ───────────────────────── accepting it ─────────────────────────

    public function test_the_accept_dialog_asks_for_a_price_for_every_unpriced_line(): void
    {
        // Arrange
        $me = $this->customer();
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->quotedProduct()))
            ->assertCreated();

        $order = Order::query()->with('items')->sole();
        $item = $order->items->first();

        // Act
        $fields = TransitionFields::for($order, OrderStatus::New);

        // Assert — asked on the move that accepts, so the reviewer quotes it in the same tap
        // rather than being refused and sent to the edit screen first.
        $price = collect($fields)->firstWhere('key', TransitionFields::unitPriceKey($item));

        $this->assertNotNull($price, 'the accept dialog asks nothing about the price');
        $this->assertTrue($price->required);
    }

    public function test_a_request_cannot_be_accepted_while_a_line_has_no_price(): void
    {
        // Arrange
        $me = $this->customer();
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->quotedProduct()))
            ->assertCreated();

        $order = Order::query()->sole();
        $headers = $this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders);

        // Act — accepted with the price box left empty.
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/orders/{$order->id}/status", ['status' => OrderStatus::New->value]);

        // Assert — **the guard the nullable column depends on.** Without it an accepted order
        // could invoice for less than the goods are worth, silently.
        //
        // The refusal lands on the field rather than on `items`, and that is the better of the
        // two: the box the reviewer left empty is the box the message appears under.
        // `OrderLinesNeedAPrice` still stands behind it in the domain, for callers that are not
        // this request — a console command, an import — and is what makes the invariant true
        // rather than merely enforced at one door.
        $item = $order->items()->sole();

        $response->assertStatus(422)
            ->assertJsonValidationErrors('fields.'.TransitionFields::unitPriceKey($item));

        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);
    }

    public function test_accepting_with_a_price_quotes_the_line_and_totals_the_order(): void
    {
        // Arrange
        $me = $this->customer();
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->quotedProduct(), 100))
            ->assertCreated();

        $order = Order::query()->with('items')->sole();
        $item = $order->items->first();
        $headers = $this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders);

        // Act — the reviewer names 2.500 a piece for a hundred.
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::New->value,
            // Inside `fields`, the way the app sends every input a transition described.
            'fields' => [TransitionFields::unitPriceKey($item) => '2.500'],
        ]);

        // Assert — the line is priced, its total derived rather than multiplied out a second
        // time, and the order totalled from it.
        $response->assertOk();

        $order->refresh()->load('items');

        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame('2.500', (string) $order->items->first()->unit_price);
        $this->assertSame('250.00', (string) $order->items->first()->line_total);
        $this->assertSame('250.00', (string) $order->grand_total);
        $this->assertFalse($order->hasUnpricedLines());
    }

    public function test_once_accepted_the_customer_sees_the_figure(): void
    {
        // Arrange
        $me = $this->customer();
        $this->withHeaders($this->bearerFor($me))
            ->postJson('/api/v1/client/orders', $this->payload($this->quotedProduct(), 100))
            ->assertCreated();

        $order = Order::query()->with('items')->sole();

        $this->withHeaders($this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders))
            ->postJson("/api/v1/orders/{$order->id}/status", [
                'status' => OrderStatus::New->value,
                'fields' => [
                    TransitionFields::unitPriceKey($order->items->first()) => '2.500',
                ],
            ])->assertOk();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->getJson("/api/v1/client/orders/{$order->id}");

        // Assert — the other side of the first test: the word is replaced by the number the
        // moment there is one.
        $response->assertOk()
            ->assertJsonPath('data.is_awaiting_quote', false)
            ->assertJsonPath('data.total', '250.00');
    }
}
