<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Client;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\CustomerOrderStage;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Refusing a request, rather than writing it off.
 *
 * **«رُفض الطلب» exists because «إلغاء تام» was doing three wrong things at once.** Cancelling
 * reverses stock, closes the shortages raised against an order and lands it in the write-off
 * reporting — none of which a request nobody accepted has behind it. It also cost
 * `orders.status.cancelled`, the grant for writing off real money, so whoever read the intake
 * queue needed authority over the books to say «no thanks».
 *
 * And for the customer the two are not the same news: «ملغاة» is an order the shop took and
 * stopped; this one was never taken. The app is sent its own stage and the sentence explaining
 * why — the one reason string that ever leaves for a customer's phone.
 *
 * **Reversible, unlike a cancellation**, because nothing happened: a mis-tap on the intake
 * queue puts the request back where it was.
 *
 * Arrange - Act - Assert throughout.
 */
class RejectedOrderRequestTest extends TestCase
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

    private function product(): Product
    {
        $category = ProductCategory::factory()->create();
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
     * A request sitting in «بانتظار المراجعة», placed the way a customer places one.
     */
    private function requestedOrder(Customer $customer): Order
    {
        $product = $this->product();

        $this->withHeaders($this->bearerFor($customer))->postJson('/api/v1/client/orders', [
            'city_id' => City::factory()->create()->id,
            'items' => [[
                'product_id' => $product->id,
                'product_variant_id' => $product->variants()->first()->id,
                'quantity' => 1000,
            ]],
        ])->assertCreated();

        return Order::query()->sole();
    }

    // ───────────────────────── refusing ─────────────────────────

    public function test_a_request_is_refused_rather_than_cancelled(): void
    {
        // Arrange
        $me = $this->customer();
        $order = $this->requestedOrder($me);

        // Act
        $response = $this->withHeaders($this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders))
            ->postJson("/api/v1/orders/{$order->id}/status", [
                'status' => OrderStatus::RequestRejected->value,
                'reason' => 'المقاس غير متوفر لدينا حالياً',
            ]);

        // Assert — its own status, its own timestamp, its own reason column. Nothing was
        // written to `cancellation_reason`, which belongs to a different question entirely.
        $response->assertOk();

        $order->refresh();

        $this->assertSame(OrderStatus::RequestRejected, $order->status);
        $this->assertSame('المقاس غير متوفر لدينا حالياً', $order->rejection_reason);
        $this->assertNotNull($order->request_rejected_at);
        $this->assertNull($order->cancellation_reason);
        $this->assertNull($order->cancelled_at);
    }

    public function test_refusing_without_a_sentence_is_refused(): void
    {
        // Arrange
        $order = $this->requestedOrder($this->customer());

        // Act
        $response = $this->withHeaders($this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders))
            ->postJson("/api/v1/orders/{$order->id}/status", [
                'status' => OrderStatus::RequestRejected->value,
            ]);

        // Assert — the sentence *is* the answer to the customer. A refusal with nothing attached
        // reaches their phone as a door shut without a word.
        $response->assertStatus(422);
        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);
    }

    public function test_a_request_can_no_longer_be_written_off(): void
    {
        // Arrange
        $order = $this->requestedOrder($this->customer());

        // Act — the move the shop used to make, and the one that ran a cancellation's machinery
        // over an order that had nothing to cancel.
        $response = $this->withHeaders(
            $this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders, PermissionName::CancelOrders)
        )->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Cancelled->value,
            'reason' => 'لا نريدها',
        ]);

        // Assert — refused by the map itself, so there is one way to end a request and it is
        // the one that records the right thing.
        $response->assertStatus(422);
        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);
    }

    public function test_refusing_costs_the_reviewers_grant_and_not_the_cancel_grant(): void
    {
        // Arrange — somebody who may read and manage the queue, and who holds no authority over
        // write-offs at all.
        $order = $this->requestedOrder($this->customer());

        // Act
        $response = $this->withHeaders($this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders))
            ->postJson("/api/v1/orders/{$order->id}/status", [
                'status' => OrderStatus::RequestRejected->value,
                'reason' => 'المقاس غير متوفر',
            ]);

        // Assert — this is half the point of the new status. Declining a request nobody accepted
        // is the reviewer's own work; requiring `orders.status.cancelled` for it handed the
        // intake queue an authority over the books it never needed.
        $response->assertOk();
        $this->assertSame(OrderStatus::RequestRejected, $order->fresh()->status);
    }

    // ───────────────────────── undoing it ─────────────────────────

    public function test_a_refusal_can_be_undone_and_takes_its_sentence_with_it(): void
    {
        // Arrange
        $order = $this->requestedOrder($this->customer());
        $headers = $this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders);

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::RequestRejected->value,
            'reason' => 'المقاس غير متوفر',
        ])->assertOk();

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Requested->value,
        ]);

        // Assert — back in the queue, and **the stale sentence is gone**. Leaving it would mean
        // an order awaiting a fresh answer still carrying — and still showing the customer — the
        // reason it was once refused.
        $response->assertOk();

        $order->refresh();

        $this->assertSame(OrderStatus::Requested, $order->status);
        $this->assertNull($order->rejection_reason);
        $this->assertNull($order->request_rejected_at);
    }

    // ───────────────────────── what the customer sees ─────────────────────────

    public function test_the_customer_is_told_it_was_refused_and_why(): void
    {
        // Arrange
        $me = $this->customer();
        $order = $this->requestedOrder($me);

        $this->withHeaders($this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders))
            ->postJson("/api/v1/orders/{$order->id}/status", [
                'status' => OrderStatus::RequestRejected->value,
                'reason' => 'المقاس غير متوفر لدينا حالياً',
            ])->assertOk();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->getJson("/api/v1/client/orders/{$order->id}");

        // Assert — its own stage, not «ملغاة», and the shop's sentence with it.
        $response->assertOk()
            ->assertJsonPath('data.stage', CustomerOrderStage::Rejected->value)
            ->assertJsonPath('data.stage_label', 'مرفوضة')
            ->assertJsonPath('data.is_open', false)
            ->assertJsonPath('data.rejection_reason', 'المقاس غير متوفر لدينا حالياً');
    }

    public function test_a_refused_request_is_not_in_the_open_list(): void
    {
        // Arrange
        $me = $this->customer();
        $order = $this->requestedOrder($me);

        $this->withHeaders($this->staff(PermissionName::ManageOrders, PermissionName::ViewOrders))
            ->postJson("/api/v1/orders/{$order->id}/status", [
                'status' => OrderStatus::RequestRejected->value,
                'reason' => 'المقاس غير متوفر',
            ])->assertOk();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->getJson('/api/v1/client/orders?open=1');

        // Assert — «قيد التنفيذ» is what is still moving, and this is not.
        $response->assertOk()->assertJsonCount(0, 'data');
    }
}
