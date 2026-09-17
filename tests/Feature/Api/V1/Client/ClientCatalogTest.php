<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Client;

use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue as a customer sees it.
 *
 * **`test_the_catalogue_never_carries_the_cost_price` is the reason this whole family of client
 * resources exists.** The staff `ProductVariantResource` hides «سعر التكلفة» behind
 * `$request->user()?->can(...)`, and a `Customer` has no `can()` at all — the model takes the
 * bare `Authenticatable` contract, never `Authorizable`, so that call is a missing method rather
 * than a silent pass. Reusing the staff resource here would therefore be a 500 at best; the real
 * danger is the version of this system where `Customer` *did* extend the framework's `User` and
 * the cost price simply went out with the response.
 *
 * So: separate resources, and these tests assert on absence rather than on a permission.
 *
 * Arrange - Act - Assert throughout.
 */
class ClientCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): Customer
    {
        return Customer::factory()->registered()->create();
    }

    /**
     * @return array<string, string>
     */
    private function bearer(?Customer $customer = null): array
    {
        $customer ??= $this->customer();

        return ['Authorization' => 'Bearer '.$customer->createToken('test-device')->plainTextToken];
    }

    /**
     * A product with one size, one price break and a cost price staff can see.
     */
    private function product(array $overrides = []): Product
    {
        $product = Product::factory()->create($overrides);

        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'cost_price' => '0.400',
        ]);

        ProductPriceTier::factory()->create([
            'product_variant_id' => $variant->id,
            'min_quantity' => 1000,
            'unit_price' => '0.950',
        ]);

        return $product->refresh();
    }

    // ─────────────────────────── the list ───────────────────────────

    public function test_the_catalogue_lists_products_with_the_pagination_envelope(): void
    {
        // Arrange
        $this->product();
        $this->product();

        // Act
        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/products');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'last_page', 'total']]);
    }

    /**
     * A product is deactivated rather than deleted, and a deactivated one is off the catalogue.
     * The staff list can ask for both; the customer's cannot.
     */
    public function test_a_deactivated_product_is_off_the_catalogue(): void
    {
        // Arrange
        $live = $this->product();
        $retired = $this->product(['is_active' => false]);

        // Act
        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/products');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $live->id);
        $this->assertNotSame($retired->id, $response->json('data.0.id'));
    }

    /**
     * `is_active=0` is a staff filter. Sending it here must not reopen the retired half of the
     * catalogue — the controller decides, not the query string.
     */
    public function test_the_customer_cannot_ask_for_deactivated_products(): void
    {
        // Arrange
        $this->product(['is_active' => false]);

        // Act
        $response = $this->withHeaders($this->bearer())
            ->getJson('/api/v1/client/products?is_active=0');

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_catalogue_can_be_filtered_by_category(): void
    {
        // Arrange
        $category = ProductCategory::factory()->create();
        $inCategory = $this->product(['product_category_id' => $category->id]);
        $this->product();

        // Act
        $response = $this->withHeaders($this->bearer())
            ->getJson("/api/v1/client/products?product_category_id={$category->id}");

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inCategory->id);
    }

    public function test_the_catalogue_can_be_searched_by_name(): void
    {
        // Arrange
        $wanted = $this->product(['name' => 'كيس شحن فلاير']);
        $this->product(['name' => 'ملصق لاصق']);

        // Act
        $response = $this->withHeaders($this->bearer())
            ->getJson('/api/v1/client/products?search='.urlencode('فلاير'));

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $wanted->id);
    }

    // ─────────────────────── what must never leave ───────────────────────

    /**
     * **The headline test.** What the shop pays for a bag is not the customer's business, on any
     * endpoint, at any nesting depth.
     */
    public function test_the_catalogue_never_carries_the_cost_price(): void
    {
        // Arrange
        $product = $this->product();

        // Act
        $list = $this->withHeaders($this->bearer())->getJson('/api/v1/client/products');
        $show = $this->withHeaders($this->bearer())->getJson("/api/v1/client/products/{$product->id}");

        // Assert
        $list->assertOk()->assertDontSee('cost_price')->assertDontSee('0.400');
        $show->assertOk()->assertDontSee('cost_price')->assertDontSee('0.400');
    }

    /**
     * Which shelf a size draws from, and what material it is filed under, are warehouse facts.
     * They say what we hold and where, and they belong to nobody outside the building.
     */
    public function test_the_catalogue_never_carries_the_warehouse_wiring(): void
    {
        // Arrange
        $product = $this->product();

        // Act
        $response = $this->withHeaders($this->bearer())->getJson("/api/v1/client/products/{$product->id}");

        // Assert
        $response->assertOk()
            ->assertDontSee('stock_item')
            ->assertDontSee('stock_item_group')
            ->assertDontSee('production_mode');
    }

    public function test_a_product_says_which_basket_it_belongs_to_and_never_why(): void
    {
        // Arrange — the cart has to refuse the second product the moment it is tapped, not at
        // the end of a filled basket. So the server sends a token to compare, and nothing the
        // app could decode into «وسيط».
        $ours = ProductCategory::factory()->create(['production_mode' => ProductionMode::InHouse]);
        $shelf = ProductCategory::factory()->create(['production_mode' => ProductionMode::None]);
        $theirs = ProductCategory::factory()->create(['production_mode' => ProductionMode::Outsourced]);

        Product::factory()->create(['product_category_id' => $ours->id, 'name' => 'أ']);
        Product::factory()->create(['product_category_id' => $shelf->id, 'name' => 'ب']);
        Product::factory()->create(['product_category_id' => $theirs->id, 'name' => 'ج']);

        // Act
        $response = $this->withHeaders($this->bearer())
            ->getJson('/api/v1/client/products?per_page=50');

        // Assert — ours and the shelf share a basket; the vendor's does not.
        $response->assertOk();

        $groups = collect($response->json('data'))->pluck('order_group', 'name');

        $this->assertSame($groups['أ'], $groups['ب']);
        $this->assertNotSame($groups['أ'], $groups['ج']);
    }

    public function test_the_basket_token_carries_no_production_vocabulary(): void
    {
        // Arrange — the wall: «what the category means for production is not on this side of
        // it». A token the app could read as «outsourced» would be the mode with a new name.
        $theirs = ProductCategory::factory()->create(['production_mode' => ProductionMode::Outsourced]);
        Product::factory()->create(['product_category_id' => $theirs->id]);

        // Act
        $response = $this->withHeaders($this->bearer())
            ->getJson('/api/v1/client/products');

        // Assert
        $product = $response->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('production_mode', $product);
        $this->assertNotContains($product['order_group'], array_map(
            fn (ProductionMode $mode): string => $mode->value,
            ProductionMode::cases(),
        ));
    }

    // ─────────────────────────── one product ───────────────────────────

    public function test_one_product_carries_its_sizes_and_price_breaks(): void
    {
        // Arrange
        $product = $this->product();

        // Act
        $response = $this->withHeaders($this->bearer())->getJson("/api/v1/client/products/{$product->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonCount(1, 'data.variants')
            // Decimal strings, cast and all — `min_quantity` is a decimal column because a
            // per-kilo product breaks at fractional weights.
            ->assertJsonPath('data.variants.0.price_tiers.0.min_quantity', '1000.000')
            ->assertJsonPath('data.variants.0.price_tiers.0.unit_price', '0.950');
    }

    public function test_a_deactivated_product_is_a_404_not_a_hidden_row(): void
    {
        // Arrange
        $retired = $this->product(['is_active' => false]);

        // Act
        $response = $this->withHeaders($this->bearer())->getJson("/api/v1/client/products/{$retired->id}");

        // Assert
        $response->assertNotFound()->assertJsonPath('status', false);
    }

    // ─────────────────────────── the price ───────────────────────────

    /**
     * The quote comes from the server so that the number the customer is shown and the number
     * written to the order come from the same code — the reason this endpoint exists at all.
     */
    public function test_a_quote_prices_a_quantity(): void
    {
        // Arrange
        $product = $this->product();
        $variant = $product->variants()->first();

        // Act
        $response = $this->withHeaders($this->bearer())->postJson(
            "/api/v1/client/products/{$product->id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 2000],
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.unit_price', '0.950')
            ->assertJsonPath('data.total', '1900.000');
    }

    public function test_a_quote_cannot_be_built_from_another_products_size(): void
    {
        // Arrange
        $product = $this->product();
        $other = $this->product();
        $foreignVariant = $other->variants()->first();

        // Act
        $response = $this->withHeaders($this->bearer())->postJson(
            "/api/v1/client/products/{$product->id}/quote",
            ['variant_id' => $foreignVariant->id, 'quantity' => 2000],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('variant_id');
    }

    public function test_a_quote_refuses_a_quantity_of_nothing(): void
    {
        // Arrange
        $product = $this->product();
        $variant = $product->variants()->first();

        // Act
        $response = $this->withHeaders($this->bearer())->postJson(
            "/api/v1/client/products/{$product->id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 0],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('quantity');
    }

    public function test_a_deactivated_product_cannot_be_quoted(): void
    {
        // Arrange
        $retired = $this->product(['is_active' => false]);
        $variant = $retired->variants()->first();

        // Act
        $response = $this->withHeaders($this->bearer())->postJson(
            "/api/v1/client/products/{$retired->id}/quote",
            ['variant_id' => $variant->id, 'quantity' => 2000],
        );

        // Assert
        $response->assertNotFound();
    }

    // ─────────────────────────── categories ───────────────────────────

    public function test_the_category_list_carries_only_what_a_filter_chip_needs(): void
    {
        // Arrange
        ProductCategory::factory()->create(['name' => 'أكياس فلاير']);

        // Act
        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/product-categories');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.name', 'أكياس فلاير')
            ->assertDontSee('is_investable')
            ->assertDontSee('production_mode');
    }

    // ─────────────────────────── the guard ───────────────────────────

    public function test_the_catalogue_needs_a_customer_token(): void
    {
        // Act
        $response = $this->getJson('/api/v1/client/products');

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_staff_token_cannot_read_the_customer_catalogue(): void
    {
        // Arrange
        $staff = ['Authorization' => 'Bearer '.User::factory()->create()->createToken('s')->plainTextToken];

        // Act
        $response = $this->withHeaders($staff)->getJson('/api/v1/client/products');

        // Assert
        $response->assertUnauthorized();
    }
}
