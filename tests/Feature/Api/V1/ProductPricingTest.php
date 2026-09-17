<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pricing — quantity breaks, minimums, and the products that refuse to be priced.
 *
 * Arrange - Act - Assert throughout.
 */
class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        $user = User::factory()->create();

        // Acts as an administrator. Endpoints are permission-guarded, and these tests are about
        // the feature rather than who may reach it — authorization has its own suites in
        // RoleTest and RoleManagementTest.
        Role::findOrCreate(RoleName::Admin->value, 'web');
        $user->syncRoles([RoleName::Admin->value]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * The real 25*35 shipping bag from the catalogue: 1.10 under 300, 0.95 from 300, 0.85 from 1000.
     */
    private function shippingBag(): ProductVariant
    {
        $product = Product::factory()->create(['min_order_quantity' => 100]);
        $variant = ProductVariant::factory()->size(25, 35)->create(['product_id' => $product->id]);

        ProductPriceTier::factory()->from(1, '1.100')->create(['product_variant_id' => $variant->id]);
        ProductPriceTier::factory()->from(300, '0.950')->create(['product_variant_id' => $variant->id]);
        ProductPriceTier::factory()->from(1000, '0.850')->create(['product_variant_id' => $variant->id]);

        return $variant->fresh(['product', 'priceTiers']);
    }

    // ─────────────────────────── tier resolution ───────────────────────────

    /**
     * The boundaries are the whole point of a tier table, so each one is pinned.
     *
     * 300 and 1000 are inclusive: a customer ordering exactly 300 gets the 300+ rate.
     */
    #[DataProvider('quantityTierCases')]
    public function test_the_applied_unit_price_matches_the_quantity_break(
        string $quantity,
        string $expectedUnitPrice,
        string $expectedTierFloor,
    ): void {
        // Arrange
        $variant = $this->shippingBag();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => $quantity],
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.unit_price', $expectedUnitPrice)
            ->assertJsonPath('data.applied_tier_min_quantity', $expectedTierFloor);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function quantityTierCases(): array
    {
        return [
            'at the product minimum' => ['100', '1.100', '1.000'],
            'just below the first break' => ['299', '1.100', '1.000'],
            'exactly at 300' => ['300', '0.950', '300.000'],
            'just above 300' => ['301', '0.950', '300.000'],
            'just below 1000' => ['999', '0.950', '300.000'],
            'exactly at 1000' => ['1000', '0.850', '1000.000'],
            'well above 1000' => ['5000', '0.850', '1000.000'],
        ];
    }

    public function test_the_total_is_the_unit_price_times_the_quantity_exactly(): void
    {
        // Arrange — 1.10 has no exact float representation; 1.10 × 300 must still be 330.
        $variant = $this->shippingBag();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 299],
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.unit_price', '1.100')
            ->assertJsonPath('data.total', '328.900');
    }

    public function test_money_is_returned_as_a_string_so_it_cannot_drift(): void
    {
        // Arrange
        $variant = $this->shippingBag();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 1000],
        );

        // Assert
        $response->assertOk();
        $this->assertIsString($response->json('data.unit_price'));
        $this->assertIsString($response->json('data.total'));
        $this->assertSame('850.000', $response->json('data.total'));
    }

    // ─────────────────────────── the next break ───────────────────────────

    public function test_the_quote_points_at_the_next_cheaper_break(): void
    {
        // Arrange
        $variant = $this->shippingBag();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 953],
        );

        // Assert — "order 47 more and the unit price drops to 0.85".
        $response->assertOk()
            ->assertJsonPath('data.next_tier.min_quantity', '1000.000')
            ->assertJsonPath('data.next_tier.unit_price', '0.850')
            ->assertJsonPath('data.next_tier.quantity_to_reach', '47.000');
    }

    public function test_there_is_no_next_break_once_the_best_rate_is_reached(): void
    {
        // Arrange
        $variant = $this->shippingBag();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 2000],
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.next_tier', null);
    }

    // ─────────────────────────── minimum order ───────────────────────────

    public function test_a_quantity_under_the_minimum_is_refused(): void
    {
        // Arrange — the product's minimum is 100.
        $variant = $this->shippingBag();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 99],
        );

        // Assert
        $response->assertStatus(422)
            ->assertJson(['status' => false, 'data' => null])
            ->assertJsonValidationErrors('quantity');
    }

    public function test_a_quantity_exactly_at_the_minimum_is_accepted(): void
    {
        // Arrange
        $variant = $this->shippingBag();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 100],
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.quantity', '100.000');
    }

    // ─────────────────────────── quote-on-request ───────────────────────────

    public function test_a_quote_on_request_product_refuses_to_invent_a_price(): void
    {
        // Arrange — the reinforced 3D paper bags have no listed prices.
        $product = Product::factory()->quoteOnRequest()->create(['name' => 'أكياس ورقية 3D']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => 'حسب الطلب']);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$product->id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 500],
        );

        // Assert
        $response->assertStatus(422)
            ->assertJson(['status' => false, 'data' => null]);
        $this->assertStringContainsString('حسب الطلب', (string) $response->json('message'));
    }

    // ─────────────────────────── per-kilo products ───────────────────────────

    public function test_a_per_kilo_product_accepts_a_fractional_quantity(): void
    {
        // Arrange — plain shipping bags at 32 د per kilo.
        $product = Product::factory()->perKilogram()->create(['min_order_quantity' => 1]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => 'سادة']);
        ProductPriceTier::factory()->from(1, '32.000')->create(['product_variant_id' => $variant->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$product->id}/quote",
            ['variant_id' => $variant->id, 'quantity' => '2.5'],
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.unit', 'kilogram')
            ->assertJsonPath('data.unit_label', 'كجم')
            ->assertJsonPath('data.total', '80.000');
    }

    public function test_a_per_piece_product_rejects_a_fractional_quantity(): void
    {
        // Arrange — half a bag is not a thing.
        $variant = $this->shippingBag();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => '150.5'],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    // ─────────────────────────── ownership and validation ───────────────────────────

    public function test_a_variant_from_another_product_cannot_be_quoted(): void
    {
        // Arrange
        $variant = $this->shippingBag();
        $otherVariant = ProductVariant::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $otherVariant->id, 'quantity' => 500],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('variant_id');
    }

    public function test_quote_requires_a_variant_and_a_quantity(): void
    {
        // Arrange
        $variant = $this->shippingBag();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/products/{$variant->product_id}/quote", []);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors(['variant_id', 'quantity']);
    }

    public function test_quote_rejects_a_zero_or_negative_quantity(): void
    {
        // Arrange
        $variant = $this->shippingBag();
        $headers = $this->auth();

        // Act
        $zero = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 0],
        );
        $negative = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => -5],
        );

        // Assert
        $zero->assertStatus(422)->assertJsonValidationErrors('quantity');
        $negative->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_a_variant_with_no_price_list_reports_that_rather_than_charging_zero(): void
    {
        // Arrange — a tiered product whose variant was never given prices.
        $product = Product::factory()->create(['min_order_quantity' => 1]);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/products/{$product->id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 500],
        );

        // Assert
        $response->assertStatus(422)->assertJson(['status' => false]);
        $this->assertStringContainsString('لا يوجد سعر', (string) $response->json('message'));
    }

    public function test_quote_requires_authentication(): void
    {
        // Arrange
        $variant = $this->shippingBag();

        // Act
        $response = $this->postJson(
            "/api/v1/products/{$variant->product_id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 500],
        );

        // Assert
        $response->assertStatus(401);
    }
}
