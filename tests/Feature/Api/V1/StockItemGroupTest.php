<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockItemGroup;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Stock item groups — «التصنيف», the family a material is filed under.
 *
 * A group holds nothing. What it does is let a *product* name its material once, after which every
 * size that product carries resolves to that material's shelf at the same size — created on the
 * spot when the material has not reached it yet. Before groups, every one of those links was a
 * separate decision somebody had to get right, and getting it wrong split one heap of bags into
 * two balances.
 *
 * The tests that matter most here are the resolution ones at the bottom: they are the whole reason
 * the table exists.
 *
 * Arrange - Act - Assert throughout.
 */
class StockItemGroupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /** @return array<string, string> */
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** @return array<string, string> */
    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewInventory);
    }

    /** @return array<string, string> */
    private function manager(): array
    {
        return $this->auth(PermissionName::ViewInventory, PermissionName::ManageInventory);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'كيس شحن',
            'default_unit' => PricingUnit::Piece->value,
        ], $overrides);
    }

    // ──────────────────────────────── the basics ────────────────────────────────

    public function test_a_manager_can_create_a_group(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-item-groups', $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.name', 'كيس شحن')
            ->assertJsonPath('data.default_unit', 'piece');
        $this->assertSame('G'.$response->json('data.id'), $response->json('data.code'));
    }

    public function test_a_new_group_starts_with_no_sizes_at_all(): void
    {
        // Arrange — the normal case: sizes arrive as products name them, so nobody has to
        // enumerate a material's sizes before using it
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-item-groups', $this->payload());

        // Assert
        $response->assertCreated();
        $this->assertDatabaseCount('stock_items', 0);
    }

    public function test_two_groups_cannot_share_a_name(): void
    {
        // Arrange — load-bearing, not tidy: a grouped item carries its group's name and
        // `stock_items` is unique on (name, size), so two groups sharing a name would fight over
        // the same shelf
        $headers = $this->manager();
        $this->withHeaders($headers)->postJson('/api/v1/stock-item-groups', $this->payload())->assertCreated();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-item-groups', $this->payload());

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_the_list_counts_the_sizes_and_the_products_without_loading_them(): void
    {
        // Arrange
        $group = StockItemGroup::factory()->create();
        StockItem::factory()->inGroup($group)->size(25, 35)->create();
        StockItem::factory()->inGroup($group)->size(35, 40)->create();
        Product::factory()->create(['stock_item_group_id' => $group->id]);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-item-groups');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.items_count', 2)
            ->assertJsonPath('data.0.products_count', 1);
    }

    public function test_reading_one_group_carries_its_sizes(): void
    {
        // Arrange
        $group = StockItemGroup::factory()->named('كيس شحن')->create();
        StockItem::factory()->inGroup($group)->size(25, 35)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/stock-item-groups/{$group->id}");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.display_name', 'كيس شحن 25*35');
    }

    public function test_listing_groups_needs_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/stock-item-groups');

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_viewer_may_not_create_a_group(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/stock-item-groups', $this->payload());

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('stock_item_groups', 0);
    }

    // ──────────────────────────── renaming reaches the sizes ────────────────────────────

    public function test_renaming_a_group_renames_every_size_of_it(): void
    {
        // Arrange — a grouped item carries its material's name, and that is what keeps
        // (name, size) able to identify one shelf. Leaving the sizes behind would produce rows
        // named after a material that no longer exists.
        $group = StockItemGroup::factory()->named('كيس شحن')->create();
        $small = StockItem::factory()->inGroup($group)->size(25, 35)->create();
        $large = StockItem::factory()->inGroup($group)->size(35, 40)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/stock-item-groups/{$group->id}", [
            'name' => 'كيس شحن مقوى',
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.name', 'كيس شحن مقوى');
        $this->assertSame('كيس شحن مقوى', $small->fresh()->name);
        $this->assertSame('كيس شحن مقوى', $large->fresh()->name);
        $this->assertSame('كيس شحن مقوى 25*35', $small->fresh()->displayName());
    }

    public function test_changing_the_default_unit_leaves_existing_sizes_alone(): void
    {
        // Arrange — the default decides what a size created *later* is counted in. An existing
        // shelf's unit is snapshotted onto every balance and batch that touched it, and moving it
        // is SetStockItemUnit's job, under locks.
        $group = StockItemGroup::factory()->create();
        $item = StockItem::factory()->inGroup($group)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/stock-item-groups/{$group->id}", [
            'name' => $group->name,
            'default_unit' => PricingUnit::Kilogram->value,
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.default_unit', 'kilogram');
        $this->assertSame(PricingUnit::Piece, $item->fresh()->unit);
    }

    // ──────────────────────────────── deleting ────────────────────────────────

    public function test_a_group_nothing_points_at_can_be_deleted(): void
    {
        // Arrange
        $group = StockItemGroup::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/stock-item-groups/{$group->id}");

        // Assert
        $response->assertOk();
        $this->assertSoftDeleted('stock_item_groups', ['id' => $group->id]);
    }

    public function test_a_group_with_sizes_cannot_be_deleted(): void
    {
        // Arrange
        $group = StockItemGroup::factory()->create();
        StockItem::factory()->inGroup($group)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/stock-item-groups/{$group->id}");

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['stock_item_group']]);
        $this->assertDatabaseHas('stock_item_groups', ['id' => $group->id, 'deleted_at' => null]);
    }

    public function test_a_group_a_product_is_made_of_cannot_be_deleted(): void
    {
        // Arrange — the foreign key is nullOnDelete, so this would not orphan a row; it would
        // quietly strip the rule that files the product's sizes, and the next save would detach
        // every one of them
        $group = StockItemGroup::factory()->create();
        Product::factory()->create(['stock_item_group_id' => $group->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/stock-item-groups/{$group->id}");

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['stock_item_group']]);
    }

    // ───────────────── the point of the whole table: automatic linking ─────────────────

    public function test_a_product_with_a_material_files_every_size_under_it_automatically(): void
    {
        // Arrange — say the material once, and nobody picks a shelf size by size
        $group = StockItemGroup::factory()->named('كيس شحن')->create();
        $headers = $this->auth(PermissionName::ViewProducts, PermissionName::ManageProducts);

        // Act
        $response = $this->withHeaders($headers)->post('/api/v1/products', [
            'name' => 'أكياس الشحن',
            'product_category_id' => $this->leafCategoryId(),
            'stock_item_group_id' => $group->id,
            'pricing_unit' => PricingUnit::Piece->value,
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
            'image' => UploadedFile::fake()->image('bag.jpg'),
            'variants' => [
                ['label' => '25*35', 'width_cm' => 25, 'height_cm' => 35],
                ['label' => '35*40', 'width_cm' => 35, 'height_cm' => 40],
            ],
        ]);

        // Assert — two sizes, two shelves, both under the material and both named after it
        $response->assertCreated();
        $this->assertSame(2, StockItem::query()->where('stock_item_group_id', $group->id)->count());
        $this->assertDatabaseHas('stock_items', [
            'stock_item_group_id' => $group->id, 'name' => 'كيس شحن', 'width_cm' => 25, 'height_cm' => 35,
        ]);

        foreach ($response->json('data.variants') as $variant) {
            $this->assertNotNull($variant['stock_item_id']);
        }
    }

    public function test_two_products_made_of_one_material_land_on_the_same_shelves(): void
    {
        // Arrange — كيس شحن سادة and كيس شحن مطبوع at 25*35 are two catalogue rows and one pile
        $group = StockItemGroup::factory()->named('كيس شحن')->create();
        $headers = $this->auth(PermissionName::ViewProducts, PermissionName::ManageProducts);

        $first = $this->withHeaders($headers)->post('/api/v1/products', [
            'name' => 'أكياس الشحن المطبوعة',
            'product_category_id' => $this->leafCategoryId(),
            'stock_item_group_id' => $group->id,
            'pricing_unit' => PricingUnit::Piece->value,
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
            'image' => UploadedFile::fake()->image('a.jpg'),
            'variants' => [['label' => '25*35', 'width_cm' => 25, 'height_cm' => 35]],
        ])->assertCreated();

        // Act — a second product, same material, same size, and nobody links anything
        $second = $this->withHeaders($headers)->post('/api/v1/products', [
            'name' => 'أكياس الشحن السادة',
            'product_category_id' => $this->leafCategoryId(),
            'stock_item_group_id' => $group->id,
            'pricing_unit' => PricingUnit::Piece->value,
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
            'image' => UploadedFile::fake()->image('b.jpg'),
            'variants' => [['label' => '25*35', 'width_cm' => 25, 'height_cm' => 35]],
        ]);

        // Assert — one shelf, not two
        $second->assertCreated();
        $this->assertSame(
            $first->json('data.variants.0.stock_item_id'),
            $second->json('data.variants.0.stock_item_id'),
        );
        $this->assertSame(1, StockItem::query()->where('stock_item_group_id', $group->id)->count());
    }

    public function test_an_explicit_stock_item_beats_the_material(): void
    {
        // Arrange — a 25*35 bag deliberately cut from a wider sheet keeps saying so
        $group = StockItemGroup::factory()->named('كيس شحن')->create();
        $wider = StockItem::factory()->named('فيلم عريض')->size(60, 80)->create();
        $headers = $this->auth(PermissionName::ViewProducts, PermissionName::ManageProducts);

        // Act
        $response = $this->withHeaders($headers)->post('/api/v1/products', [
            'name' => 'أكياس الشحن',
            'product_category_id' => $this->leafCategoryId(),
            'stock_item_group_id' => $group->id,
            'pricing_unit' => PricingUnit::Piece->value,
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
            'image' => UploadedFile::fake()->image('bag.jpg'),
            'variants' => [
                ['label' => '25*35', 'width_cm' => 25, 'height_cm' => 35, 'stock_item_id' => $wider->id],
            ],
        ]);

        // Assert — the explicit choice stands, and the material created nothing
        $response->assertCreated()
            ->assertJsonPath('data.variants.0.stock_item_id', $wider->id);
        $this->assertSame(0, StockItem::query()->where('stock_item_group_id', $group->id)->count());
    }

    public function test_a_product_with_no_material_still_leaves_its_sizes_unlinked(): void
    {
        // Arrange — the behaviour before groups existed, unchanged: a quote-only product has no
        // material and no shelf, and every stock path refuses such a size by name
        $headers = $this->auth(PermissionName::ViewProducts, PermissionName::ManageProducts);

        // Act
        $response = $this->withHeaders($headers)->post('/api/v1/products', [
            'name' => 'أكياس ورقية 3D',
            'product_category_id' => $this->leafCategoryId(),
            'pricing_unit' => PricingUnit::Piece->value,
            'pricing_mode' => 'quote_on_request',
            'min_order_quantity' => 200,
            'image' => UploadedFile::fake()->image('bag.jpg'),
            'variants' => [['label' => 'حسب الطلب']],
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.variants.0.stock_item_id', null);
        $this->assertDatabaseCount('stock_items', 0);
    }

    public function test_the_created_size_takes_the_materials_default_unit_not_the_products(): void
    {
        // Arrange — a thing bought in by weight and sold by the piece needs those two to differ,
        // and the shelf's side of that pair belongs to the material
        $group = StockItemGroup::factory()->named('فيلم')->weighed()->create();
        $headers = $this->auth(PermissionName::ViewProducts, PermissionName::ManageProducts);

        // Act
        $response = $this->withHeaders($headers)->post('/api/v1/products', [
            'name' => 'أكياس',
            'product_category_id' => $this->leafCategoryId(),
            'stock_item_group_id' => $group->id,
            'pricing_unit' => PricingUnit::Piece->value,
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
            'image' => UploadedFile::fake()->image('bag.jpg'),
            'variants' => [['label' => '25*35', 'width_cm' => 25, 'height_cm' => 35]],
        ]);

        // Assert — the product is sold by the piece, the shelf is weighed
        $response->assertCreated();
        $this->assertDatabaseHas('stock_items', [
            'stock_item_group_id' => $group->id, 'unit' => 'kilogram',
        ]);
    }

    public function test_adding_a_size_later_creates_only_the_shelf_it_needs(): void
    {
        // Arrange — the material already covers 25*35
        $group = StockItemGroup::factory()->named('كيس شحن')->create();
        $existing = StockItem::factory()->inGroup($group)->size(25, 35)->create();
        $product = Product::factory()->create(['stock_item_group_id' => $group->id]);
        ProductVariant::factory()->for($product)->drawingFrom($existing)->size(25, 35)->create();
        $headers = $this->auth(PermissionName::ViewProducts, PermissionName::ManageProducts);

        // Act — a second size arrives on the product
        $response = $this->withHeaders($headers)->putJson("/api/v1/products/{$product->id}", [
            'name' => $product->name,
            'slug' => $product->slug,
            'product_category_id' => $this->leafCategoryId(),
            'pricing_unit' => $product->pricing_unit->value,
            'pricing_mode' => $product->pricing_mode->value,
            'min_order_quantity' => 100,
            'variants' => [
                ['label' => '25*35', 'width_cm' => 25, 'height_cm' => 35],
                ['label' => '45*50', 'width_cm' => 45, 'height_cm' => 50],
            ],
        ]);

        // Assert — the existing shelf is reused, one new one is minted
        $response->assertOk();
        $this->assertSame(2, StockItem::query()->where('stock_item_group_id', $group->id)->count());
        $this->assertSame(
            $existing->id,
            (int) ProductVariant::query()->where('label', '25*35')->value('stock_item_id'),
        );
    }

    public function test_a_material_never_disturbs_a_balance_that_already_exists(): void
    {
        // Arrange — the shelf is already stocked; re-saving the product must reuse it, not mint a
        // second pile beside it
        $group = StockItemGroup::factory()->named('كيس شحن')->create();
        $item = StockItem::factory()->inGroup($group)->size(25, 35)->create();
        WarehouseStock::factory()->quantity('400.000')->create(['stock_item_id' => $item->id]);
        $product = Product::factory()->create(['stock_item_group_id' => $group->id]);
        $headers = $this->auth(PermissionName::ViewProducts, PermissionName::ManageProducts);

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/products/{$product->id}", [
            'name' => $product->name,
            'slug' => $product->slug,
            'product_category_id' => $this->leafCategoryId(),
            'pricing_unit' => $product->pricing_unit->value,
            'pricing_mode' => $product->pricing_mode->value,
            'min_order_quantity' => 100,
            'variants' => [['label' => '25*35', 'width_cm' => 25, 'height_cm' => 35]],
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.variants.0.stock_item_id', $item->id);
        $this->assertDatabaseHas('warehouse_stocks', [
            'stock_item_id' => $item->id, 'quantity' => '400.000',
        ]);
        $this->assertSame(1, StockItem::query()->where('stock_item_group_id', $group->id)->count());
    }

    /** A heading a product may actually be filed under — the one-level rule wants a leaf. */
    private function leafCategoryId(): int
    {
        return (int) ProductCategory::factory()->create()->getKey();
    }
}
