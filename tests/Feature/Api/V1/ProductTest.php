<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The product catalogue endpoints.
 *
 * Creating a product is `multipart/form-data`, not JSON, because a photo is required and arrives
 * with it — see PRODUCT-IMAGE-REQUIRED-DESIGN.md. Updating is still JSON, which is why the two
 * halves of this suite call different helpers.
 *
 * Arrange - Act - Assert throughout.
 */
class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every create writes a photo, so the media disk is faked for the whole suite rather
        // than in each test that happens to reach storage.
        Storage::fake((string) config('media.disk'));
    }

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

    private ?int $categoryId = null;

    /**
     * The catalogue heading every product now needs — «التصنيف».
     *
     * Made once per test and reused: the field is required on create *and* on update, so a
     * fresh row per call would leave a trail of categories nothing points at.
     */
    private function categoryId(): int
    {
        return $this->categoryId ??= ProductCategory::factory()->create()->id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_category_id' => $this->categoryId(),
            'slug' => 'shipping-bag',
            'name' => 'أكياس الشحن',
            'pricing_unit' => 'piece',
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
        ], $overrides);
    }

    /**
     * Posts a create as a browser would: `multipart/form-data`, photo included.
     *
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $overrides  merged into the body; pass `image` to override or
     *                                           `null` to leave the photo out entirely.
     */
    private function create(array $headers, array $overrides = []): TestResponse
    {
        $body = $this->payload($overrides);

        if (! array_key_exists('image', $body)) {
            $body['image'] = UploadedFile::fake()->image('bag.jpg', 800, 600);
        }

        return $this->withHeaders($headers)->post(
            '/api/v1/products',
            array_filter($body, static fn (mixed $value): bool => $value !== null),
        );
    }

    // ─────────────────────────── create ───────────────────────────

    public function test_create_requires_a_photo(): void
    {
        // Arrange — a catalogue entry with no picture is a gap in the grid, so the product is
        // refused rather than created and left to be photographed later.
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, ['image' => null]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_create_stores_the_photo_as_the_products_primary_image(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->create($headers);

        // Assert
        $response->assertCreated()->assertJsonCount(1, 'data.images');

        $image = ProductImage::query()->firstOrFail();
        $this->assertTrue($image->is_primary);
        $this->assertSame(Product::query()->firstOrFail()->id, $image->product_id);
        Storage::disk((string) config('media.disk'))->assertExists($image->path);
    }

    public function test_create_accepts_an_alt_text_for_the_photo(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, ['image_alt_text' => 'كيس شحن أبيض']);

        // Assert
        $response->assertCreated()->assertJsonPath('data.images.0.alt_text', 'كيس شحن أبيض');
    }

    public function test_create_rejects_a_file_that_is_not_an_image(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, [
            'image' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf'),
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_create_leaves_nothing_behind_when_the_body_is_rejected(): void
    {
        // Arrange — the product, its sizes and its photo are one transaction. A 422 on the sizes
        // must not leave a product and an orphaned image row behind.
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, ['variants' => [['width_cm' => 25]]]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('variants.0.label');
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_create_reads_a_multipart_false_as_false(): void
    {
        // Arrange — form encoding sends every value as text, and `(bool) "false"` is *true* in
        // PHP. Without normalisation this product would come back active.
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, ['is_active' => 'false']);

        // Assert
        $response->assertCreated()->assertJsonPath('data.is_active', false);
    }

    public function test_create_stores_the_product_with_its_sizes_and_price_list(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, [
            'features' => ['مقاومة للماء والتمزق', 'لاصق قوي واحترافي'],
            'variants' => [
                [
                    'label' => '25*35',
                    'width_cm' => 25,
                    'height_cm' => 35,
                    'price_tiers' => [
                        ['min_quantity' => 1, 'unit_price' => '1.10'],
                        ['min_quantity' => 300, 'unit_price' => '0.95'],
                        ['min_quantity' => 1000, 'unit_price' => '0.85'],
                    ],
                ],
            ],
        ]);

        // Assert
        $response->assertCreated()
            ->assertJson([
                'status' => true,
                'message' => 'تم إضافة المنتج بنجاح',
                'data' => [
                    'slug' => 'shipping-bag',
                    'pricing_unit' => 'piece',
                    'pricing_mode' => 'tiered',
                    'has_listed_prices' => true,
                    'is_active' => true,
                ],
            ])
            // «النوع» is gone: مطبوعة/سادة is a heading in the categories table now, not a
            // second word on every product. See PRODUCT-CATEGORIES.md.
            ->assertJsonMissingPath('data.category')
            ->assertJsonMissingPath('data.category_label')
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonCount(3, 'data.variants.0.price_tiers')
            ->assertJsonPath('data.variants.0.price_tiers.0.unit_price', '1.100')
            ->assertJsonPath('data.features.0', 'مقاومة للماء والتمزق');

        $this->assertDatabaseHas('products', ['slug' => 'shipping-bag']);
        $this->assertDatabaseCount('product_price_tiers', 3);
    }

    public function test_create_accepts_a_per_kilo_product_with_a_fractional_minimum(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, [
            'slug' => 'general-shipping-bag',
            'name' => 'أكياس الشحن السادة',
            'pricing_unit' => 'kilogram',
            'min_order_quantity' => '2.5',
            'variants' => [
                ['label' => 'سادة', 'price_tiers' => [['min_quantity' => 1, 'unit_price' => '32']]],
            ],
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.pricing_unit', 'kilogram')
            ->assertJsonPath('data.pricing_unit_label', 'كجم')
            ->assertJsonPath('data.min_order_quantity', '2.500');
    }

    public function test_create_rejects_a_fractional_minimum_for_a_per_piece_product(): void
    {
        // Arrange — you cannot require a minimum of two and a half bags.
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, ['pricing_unit' => 'piece', 'min_order_quantity' => '2.5']);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('min_order_quantity');
    }

    public function test_create_refuses_to_put_prices_on_a_quote_only_product(): void
    {
        // Arrange — a listed price would contradict "price on request".
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, [
            'slug' => 'paper-bag-3d',
            'pricing_mode' => 'quote_on_request',
            'min_order_quantity' => 200,
            'variants' => [
                ['label' => 'حسب الطلب', 'price_tiers' => [['min_quantity' => 1, 'unit_price' => '5.00']]],
            ],
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('variants.0.price_tiers');
    }

    public function test_create_allows_a_quote_only_product_without_prices(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, [
            'slug' => 'paper-bag-3d',
            'name' => 'أكياس ورقية 3D',
            'pricing_mode' => 'quote_on_request',
            'min_order_quantity' => 200,
            'variants' => [['label' => 'حسب الطلب']],
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.pricing_mode', 'quote_on_request')
            ->assertJsonPath('data.pricing_mode_label', 'السعر حسب الطلب')
            ->assertJsonPath('data.has_listed_prices', false)
            ->assertJsonCount(0, 'data.variants.0.price_tiers');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidProductCases')]
    public function test_create_rejects_invalid_input(array $overrides, string $invalidField): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, $overrides);

        // Assert
        $response->assertStatus(422)
            ->assertJson(['status' => false, 'data' => null])
            ->assertJsonValidationErrors($invalidField);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidProductCases(): array
    {
        return [
            // A missing slug is no longer invalid: the server derives one from the name and the
            // product's code. See ProductSlugTest, which owns that behaviour. What is still
            // refused is a slug that *was* sent and is not one.
            'slug with spaces' => [['slug' => 'shipping bag'], 'slug'],
            'slug with arabic' => [['slug' => 'كيس'], 'slug'],
            'slug uppercase' => [['slug' => 'Shipping-Bag'], 'slug'],
            'name missing' => [['name' => ''], 'name'],
            'unknown pricing unit' => [['pricing_unit' => 'metre'], 'pricing_unit'],
            'unknown pricing mode' => [['pricing_mode' => 'auction'], 'pricing_mode'],
            'minimum of zero' => [['min_order_quantity' => 0], 'min_order_quantity'],
            'negative minimum' => [['min_order_quantity' => -5], 'min_order_quantity'],
            'variant without a label' => [['variants' => [['width_cm' => 25]]], 'variants.0.label'],
            'negative unit price' => [
                ['variants' => [['label' => '25*35', 'price_tiers' => [['min_quantity' => 1, 'unit_price' => -1]]]]],
                'variants.0.price_tiers.0.unit_price',
            ],
            'tier without a price' => [
                ['variants' => [['label' => '25*35', 'price_tiers' => [['min_quantity' => 1]]]]],
                'variants.0.price_tiers.0.unit_price',
            ],
        ];
    }

    public function test_create_rejects_a_duplicate_slug(): void
    {
        // Arrange
        Product::factory()->create(['slug' => 'shipping-bag']);
        $headers = $this->auth();

        // Act
        $response = $this->create($headers);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_create_requires_authentication(): void
    {
        // Act
        $response = $this->create([]);

        // Assert
        $response->assertStatus(401);
    }

    // ─────────────────────────── list ───────────────────────────

    public function test_index_returns_a_paginated_catalogue_in_sort_order(): void
    {
        // Arrange
        Product::factory()->create(['slug' => 'third', 'sort_order' => 3]);
        Product::factory()->create(['slug' => 'first', 'sort_order' => 1]);
        Product::factory()->create(['slug' => 'second', 'sort_order' => 2]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/products');

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [['id', 'slug', 'name', 'product_category_id', 'pricing_unit', 'pricing_mode', 'variants']],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ]);

        $this->assertSame(['first', 'second', 'third'], array_column($response->json('data'), 'slug'));
    }

    public function test_index_handles_an_empty_catalogue(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/products');

        // Assert
        $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    public function test_index_paginates_and_caps_the_page_size(): void
    {
        // Arrange
        Product::factory()->count(5)->create();
        $headers = $this->auth();

        // Act
        $paged = $this->withHeaders($headers)->getJson('/api/v1/products?per_page=2');
        $capped = $this->withHeaders($headers)->getJson('/api/v1/products?per_page=100000');

        // Assert
        $paged->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 3);
        $capped->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_index_filters_by_unit_and_mode(): void
    {
        // Arrange — `?category=` is gone with «النوع»; the catalogue heading is filtered by
        // `?product_category_id=`, which ProductCategoryTest owns.
        $printed = Product::factory()->create(['slug' => 'printed-one']);
        $general = Product::factory()->perKilogram()->create(['slug' => 'general-one']);
        $quoted = Product::factory()->quoteOnRequest()->create(['slug' => 'quoted-one']);
        $headers = $this->auth();

        // Act
        $byUnit = $this->withHeaders($headers)->getJson('/api/v1/products?pricing_unit=kilogram');
        $byMode = $this->withHeaders($headers)->getJson('/api/v1/products?pricing_mode=quote_on_request');

        // Assert
        $this->assertSame([$general->id], array_column($byUnit->json('data'), 'id'));
        $this->assertSame([$quoted->id], array_column($byMode->json('data'), 'id'));
        $this->assertNotContains($printed->id, array_column($byUnit->json('data'), 'id'));
    }

    public function test_index_searches_by_name_and_slug(): void
    {
        // Arrange
        $target = Product::factory()->create(['slug' => 'shipping-bag', 'name' => 'أكياس الشحن']);
        Product::factory()->create(['slug' => 'paper-bag', 'name' => 'أكياس ورقية']);
        $headers = $this->auth();

        foreach (['الشحن', 'shipping'] as $term) {
            // Act
            $response = $this->withHeaders($headers)->getJson('/api/v1/products?search='.urlencode($term));

            // Assert
            $response->assertOk();
            $this->assertSame(
                [$target->id],
                array_column($response->json('data'), 'id'),
                "Search term [{$term}] should match only the target product.",
            );
        }
    }

    public function test_index_filters_by_activity(): void
    {
        // Arrange
        $active = Product::factory()->create();
        $inactive = Product::factory()->inactive()->create();
        $headers = $this->auth();

        // Act
        $activeOnly = $this->withHeaders($headers)->getJson('/api/v1/products?is_active=1');
        $inactiveOnly = $this->withHeaders($headers)->getJson('/api/v1/products?is_active=0');

        // Assert
        $this->assertSame([$active->id], array_column($activeOnly->json('data'), 'id'));
        $this->assertSame([$inactive->id], array_column($inactiveOnly->json('data'), 'id'));
    }

    public function test_index_requires_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/products');

        // Assert
        $response->assertStatus(401);
    }

    // ─────────────────────────── show ───────────────────────────

    public function test_show_returns_the_product_with_variants_and_prices(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        ProductPriceTier::factory()->from(1, '1.100')->create(['product_variant_id' => $variant->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/products/{$product->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.variants.0.price_tiers.0.unit_price', '1.100');
    }

    public function test_show_returns_404_for_an_unknown_product(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/products/999999');

        // Assert
        $response->assertNotFound()->assertJson(['status' => false, 'data' => null]);
    }

    // ─────────────────────────── update ───────────────────────────

    public function test_update_changes_the_basic_fields(): void
    {
        // Arrange
        $product = Product::factory()->create(['slug' => 'old-slug', 'name' => 'قديم']);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/products/{$product->id}",
            $this->payload(['slug' => 'new-slug', 'name' => 'جديد']),
        );

        // Assert
        $response->assertOk()
            ->assertJson(['message' => 'تم تحديث المنتج بنجاح'])
            ->assertJsonPath('data.slug', 'new-slug')
            ->assertJsonPath('data.name', 'جديد');
    }

    public function test_update_allows_a_product_to_keep_its_own_slug(): void
    {
        // Arrange
        $product = Product::factory()->create(['slug' => 'shipping-bag']);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/products/{$product->id}", $this->payload(['slug' => 'shipping-bag']));

        // Assert
        $response->assertOk()->assertJsonPath('data.slug', 'shipping-bag');
    }

    /**
     * **The product form has no slug box.** It stopped collecting one when the server began
     * generating them, so every edit the app sends arrives with no `slug` key at all — and for a
     * while `required` on the update rule turned each of those saves into a 422 reading «المعرف
     * مطلوب», about a field nobody could see, let alone fill.
     *
     * Absent means "leave it": the existing slug is what links already point at.
     */
    public function test_update_without_a_slug_keeps_the_existing_one(): void
    {
        // Arrange
        $product = Product::factory()->create(['slug' => 'shipping-bag', 'name' => 'قديم']);
        $headers = $this->auth();

        $payload = $this->payload(['name' => 'جديد']);
        unset($payload['slug']);

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/products/{$product->id}", $payload);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.slug', 'shipping-bag')
            ->assertJsonPath('data.name', 'جديد');
    }

    public function test_update_rejects_a_slug_taken_by_another_product(): void
    {
        // Arrange
        $product = Product::factory()->create(['slug' => 'mine']);
        Product::factory()->create(['slug' => 'theirs']);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/products/{$product->id}", $this->payload(['slug' => 'theirs']));

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_update_without_is_active_does_not_reactivate_a_deactivated_product(): void
    {
        // Arrange
        $product = Product::factory()->inactive()->create();
        $headers = $this->auth();

        // Act — payload deliberately omits is_active.
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/products/{$product->id}", $this->payload());

        // Assert
        $response->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_update_leaves_the_price_list_alone_when_variants_are_absent(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        ProductPriceTier::factory()->from(1, '1.100')->create(['product_variant_id' => $variant->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/products/{$product->id}", $this->payload());

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data.variants');
        $this->assertDatabaseCount('product_price_tiers', 1);
    }

    public function test_update_syncs_variants_updating_adding_and_removing_in_one_call(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $kept = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => '25*35']);
        $removed = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => '99*99']);
        ProductPriceTier::factory()->from(1, '9.999')->create(['product_variant_id' => $kept->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/products/{$product->id}",
            $this->payload(['variants' => [
                ['id' => $kept->id, 'label' => '25*35', 'price_tiers' => [['min_quantity' => 1, 'unit_price' => '1.10']]],
                ['label' => '40*50', 'price_tiers' => [['min_quantity' => 1, 'unit_price' => '2.00']]],
            ]]),
        );

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data.variants');

        // The kept variant survived with its id, and its price list was replaced.
        $this->assertDatabaseHas('product_variants', ['id' => $kept->id, 'label' => '25*35']);
        // Soft deleted rather than erased — out of the live set, still on record.
        $this->assertSoftDeleted('product_variants', ['id' => $removed->id]);
        $this->assertDatabaseHas('product_price_tiers', ['product_variant_id' => $kept->id, 'unit_price' => '1.100']);
        $this->assertSoftDeleted('product_price_tiers', ['unit_price' => '9.999']);
        $this->assertSame(0, ProductPriceTier::query()->where('unit_price', '9.999')->count());
    }

    public function test_update_removing_a_variant_removes_its_prices_too(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        ProductPriceTier::factory()->count(3)->create(['product_variant_id' => $variant->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/products/{$product->id}", $this->payload(['variants' => []]));

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data.variants');
        $this->assertSame(0, ProductVariant::query()->count());
        $this->assertSame(0, ProductPriceTier::query()->count());
    }

    public function test_update_matches_an_existing_size_by_label_when_no_id_is_sent(): void
    {
        // Arrange — the owner resends the whole product with its sizes, without tracking the
        // internal variant ids. A label is unique per product, so it is the natural key.
        $product = Product::factory()->create();
        $existing = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => '25*35']);
        ProductPriceTier::factory()->from(1, '1.100')->create(['product_variant_id' => $existing->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/products/{$product->id}",
            $this->payload(['variants' => [
                ['label' => '25*35', 'width_cm' => 25, 'height_cm' => 35, 'price_tiers' => [
                    ['min_quantity' => 1, 'unit_price' => '1.25'],
                ]],
            ]]),
        );

        // Assert — the same row was updated rather than a duplicate being attempted.
        $response->assertOk()->assertJsonCount(1, 'data.variants');
        $this->assertSame($existing->id, $response->json('data.variants.0.id'));
        $this->assertDatabaseCount('product_variants', 1);
        $this->assertDatabaseHas('product_price_tiers', ['product_variant_id' => $existing->id, 'unit_price' => '1.250']);
    }

    public function test_update_is_idempotent_when_the_same_payload_is_sent_twice(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();
        $payload = $this->payload(['variants' => [
            ['label' => '25*35', 'price_tiers' => [['min_quantity' => 1, 'unit_price' => '1.10']]],
        ]]);

        // Act
        $first = $this->withHeaders($headers)->putJson("/api/v1/products/{$product->id}", $payload);
        $second = $this->withHeaders($headers)->putJson("/api/v1/products/{$product->id}", $payload);

        // Assert
        // Counted through the models so the soft-delete scope applies: replacing a price list
        // leaves the previous rows behind as history, and only the live set must stay at one.
        $first->assertOk();
        $second->assertOk()->assertJsonCount(1, 'data.variants');
        $this->assertSame(1, ProductVariant::query()->count());
        $this->assertSame(1, ProductPriceTier::query()->count());
    }

    public function test_update_rejects_the_same_size_listed_twice_in_one_request(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/products/{$product->id}",
            $this->payload(['variants' => [['label' => '25*35'], ['label' => '25*35']]]),
        );

        // Assert — a clear 422, not a unique-constraint 500.
        $response->assertStatus(422)->assertJsonValidationErrors('variants.0.label');
    }

    public function test_update_rejects_renaming_a_size_onto_one_that_already_exists(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $first = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => '25*35']);
        ProductVariant::factory()->create(['product_id' => $product->id, 'label' => '35*40']);
        $headers = $this->auth();

        // Act — rename 25*35 to 35*40, which the other variant holds.
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/products/{$product->id}",
            $this->payload(['variants' => [
                ['id' => $first->id, 'label' => '35*40'],
                ['label' => '50*60'],
            ]]),
        );

        // Assert
        $response->assertStatus(422)->assertJson(['status' => false]);
        $this->assertDatabaseHas('product_variants', ['id' => $first->id, 'label' => '25*35']);
    }

    public function test_update_rejects_a_variant_id_belonging_to_another_product(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $foreign = ProductVariant::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/products/{$product->id}",
            $this->payload(['variants' => [['id' => $foreign->id, 'label' => 'اختراق']]]),
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('variants.0.id');
        $this->assertDatabaseHas('product_variants', ['id' => $foreign->id, 'label' => $foreign->label]);
    }

    public function test_update_requires_authentication(): void
    {
        // Arrange
        $product = Product::factory()->create();

        // Act
        $response = $this->putJson("/api/v1/products/{$product->id}", $this->payload());

        // Assert
        $response->assertStatus(401);
    }

    // ─────────────────────────── activation ───────────────────────────

    public function test_activation_can_deactivate_and_reactivate_a_product(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act
        $off = $this->withHeaders($headers)
            ->patchJson("/api/v1/products/{$product->id}/activation", ['is_active' => false]);
        $on = $this->withHeaders($headers)
            ->patchJson("/api/v1/products/{$product->id}/activation", ['is_active' => true]);

        // Assert
        $off->assertOk()->assertJson(['message' => 'تم إلغاء تنشيط المنتج'])->assertJsonPath('data.is_active', false);
        $on->assertOk()->assertJson(['message' => 'تم تنشيط المنتج'])->assertJsonPath('data.is_active', true);
    }

    public function test_activation_requires_the_flag(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->patchJson("/api/v1/products/{$product->id}/activation", []);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    // ─────────────────────────── deletion is not offered ───────────────────────────

    public function test_products_cannot_be_deleted(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act — deactivation replaces deletion, so the route does not exist.
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/products/{$product->id}");

        // Assert
        $response->assertStatus(405);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    // ─────────────────────────── cascade ───────────────────────────

    public function test_force_deleting_a_product_cascades_to_variants_and_prices(): void
    {
        // Arrange — the database-level guarantee, which only a real delete exercises. A soft
        // delete leaves the product row in place, so nothing cascades.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        ProductPriceTier::factory()->count(2)->create(['product_variant_id' => $variant->id]);

        // Act
        $product->forceDelete();

        // Assert
        $this->assertSame(0, ProductVariant::withTrashed()->count());
        $this->assertSame(0, ProductPriceTier::withTrashed()->count());
    }

    // ─────────────────────────── سعر التكلفة ───────────────────────────
    // A cost price belongs to a وسيط size and to nobody else's eyes — OUTSOURCED-PRODUCTS.md §3.

    /** A heading whose goods an outside vendor makes. */
    private function outsourcedCategoryId(): int
    {
        return ProductCategory::factory()->outsourced()->create(['name' => 'وسيط'])->id;
    }

    /** Somebody who maintains the catalogue but was never shown what the shop pays. */
    private function priceBlindHeaders(): array
    {
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewProducts->value, PermissionName::ManageProducts->value);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_a_vendor_made_size_carries_a_cost_price(): void
    {
        // Arrange — 50 كرت بزنس: sold at 50, bought at 25.
        $headers = $this->auth();

        // Act
        $response = $this->create($headers, [
            'product_category_id' => $this->outsourcedCategoryId(),
            'variants' => [[
                'label' => '9*5',
                'cost_price' => '25.000',
                'price_tiers' => [['min_quantity' => 50, 'unit_price' => '50.000']],
            ]],
        ]);

        // Assert — both numbers on the same size, which is the only place they can be compared.
        $response->assertCreated()
            ->assertJsonPath('data.variants.0.cost_price', '25.000')
            ->assertJsonPath('data.variants.0.price_tiers.0.unit_price', '50.000');
    }

    public function test_a_cost_price_is_refused_on_goods_we_make_ourselves(): void
    {
        // Act — an ordinary printed heading, with a cost typed onto it.
        $response = $this->create($this->auth(), [
            'variants' => [['label' => '25*35', 'cost_price' => '25.000']],
        ]);

        // Assert — a refusal rather than a silent drop: what we print is costed from what it
        // consumed, and a second number nothing reads would answer a margin report wrongly and
        // confidently.
        $response->assertStatus(422)->assertJsonValidationErrors('variants.0.cost_price');
    }

    public function test_a_size_under_a_vendor_heading_may_have_no_cost_yet(): void
    {
        // Act — the heading is «وسيط», but nobody has agreed a price with the vendor.
        $response = $this->create($this->auth(), [
            'product_category_id' => $this->outsourcedCategoryId(),
            'variants' => [['label' => '9*5']],
        ]);

        // Assert — null is «لم تُحدَّد بعد», which is a real state a product sits in between being
        // listed and being priced.
        $response->assertCreated()->assertJsonPath('data.variants.0.cost_price', null);
    }

    public function test_the_cost_price_is_withheld_from_whoever_may_not_see_it(): void
    {
        // Arrange — a وسيط size with a cost on it. Built here rather than posted: the point of
        // this test is who reads it, and a create call would authenticate an administrator first,
        // whose guard would still be resolved when the read below ran.
        $product = Product::factory()->create(['product_category_id' => $this->outsourcedCategoryId()]);
        ProductVariant::factory()->for($product)->create(['label' => '9*5', 'cost_price' => '25.000']);

        // Act — read by somebody holding `products.view` and `products.manage`, and not
        // `products.view_cost`.
        $response = $this->getJson('/api/v1/products', $this->priceBlindHeaders());

        // Assert — **the key is absent, not null**: null would say «هذا المقاس بلا تكلفة», which
        // is a claim about the product rather than about the reader.
        $response->assertOk()->assertJsonMissingPath('data.0.variants.0.cost_price');
    }
}
