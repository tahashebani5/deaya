<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * التصنيفات — the headings the catalogue is organised under: أكياس, علب وكراتين, ستيكرات.
 *
 * The rule this feature exists to keep: **a category any product points at cannot be deleted.**
 * Deleting is soft, so the row would survive and the products would keep pointing at it while
 * the API stopped returning it — a heading nobody can name or restore. Deactivating is what the
 * person actually wants.
 *
 * Arrange - Act - Assert throughout.
 */
class ProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        $user = User::factory()->create();

        Role::findOrCreate(RoleName::Admin->value, 'web');
        $user->syncRoles([RoleName::Admin->value]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** Somebody who may read the catalogue and nothing more. */
    private function readerHeaders(): array
    {
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewProducts->value);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ─────────────────────────── the list ───────────────────────────

    public function test_categories_come_back_in_the_catalogues_own_order(): void
    {
        // Arrange
        $headers = $this->auth();
        ProductCategory::factory()->create(['name' => 'ستيكرات', 'sort_order' => 3]);
        ProductCategory::factory()->create(['name' => 'أكياس', 'sort_order' => 1]);
        ProductCategory::factory()->create(['name' => 'علب وكراتين', 'sort_order' => 2]);

        // Act
        $response = $this->getJson('/api/v1/product-categories', $headers);

        // Assert — the business's order, not the alphabet's: Arabic collation differs between
        // databases and would bury the category most products are in.
        $response->assertOk()
            ->assertJsonPath('data.0.name', 'أكياس')
            ->assertJsonPath('data.1.name', 'علب وكراتين')
            ->assertJsonPath('data.2.name', 'ستيكرات');
    }

    public function test_each_row_says_how_many_products_are_under_it(): void
    {
        // Arrange
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);
        Product::factory()->count(2)->create(['product_category_id' => $bags->id]);

        // Act
        $response = $this->getJson('/api/v1/product-categories', $headers);

        // Assert — the same number that decides whether a delete is refused, so the screen can
        // say so before the button is pressed.
        $response->assertOk()->assertJsonPath('data.0.products_count', 2);
    }

    public function test_a_picker_can_ask_for_the_live_ones_only(): void
    {
        // Arrange
        $headers = $this->auth();
        ProductCategory::factory()->create(['name' => 'أكياس']);
        ProductCategory::factory()->inactive()->create(['name' => 'تصنيف قديم']);

        // Act
        $response = $this->getJson('/api/v1/product-categories?is_active=1', $headers);

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'أكياس');
    }

    // ─────────────────────────── creating and editing ───────────────────────────

    public function test_an_administrator_adds_a_category(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->postJson('/api/v1/product-categories', [
            'name' => 'أكياس',
            'description' => 'تشكيلة أكياس مخصصة للتغليف والشحن',
            'sort_order' => 1,
        ], $headers);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.name', 'أكياس')
            ->assertJsonPath('data.description', 'تشكيلة أكياس مخصصة للتغليف والشحن')
            // Offered the moment it is created; hiding one is a later, deliberate act.
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.products_count', 0);
    }

    public function test_the_same_name_twice_is_refused(): void
    {
        // Arrange
        $headers = $this->auth();
        ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act
        $response = $this->postJson('/api/v1/product-categories', ['name' => 'أكياس'], $headers);

        // Assert — a readable 422, not the 500 a bare constraint violation would produce.
        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_a_name_freed_by_a_delete_can_be_used_again(): void
    {
        // Arrange — the partial unique index and the `withoutTrashed` rule have to agree, or one
        // of them answers 500 where the other answers a message.
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);
        $this->deleteJson("/api/v1/product-categories/{$category->id}", [], $headers)->assertOk();

        // Act
        $response = $this->postJson('/api/v1/product-categories', ['name' => 'أكياس'], $headers);

        // Assert
        $response->assertCreated();
    }

    public function test_renaming_applies_to_the_products_already_under_it(): void
    {
        // Arrange — a label, not a snapshot: fixing a heading spelt wrong fixes it everywhere.
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'ستكرات']);
        $product = Product::factory()->create(['product_category_id' => $category->id]);

        // Act
        $response = $this->putJson("/api/v1/product-categories/{$category->id}", [
            'name' => 'ستيكرات ومطبوعات أخرى',
        ], $headers);

        // Assert
        $response->assertOk()->assertJsonPath('data.name', 'ستيكرات ومطبوعات أخرى');
        $this->assertSame('ستيكرات ومطبوعات أخرى', $product->fresh()->productCategory->name);
    }

    public function test_a_category_keeping_its_own_name_does_not_collide_with_itself(): void
    {
        // Arrange
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act — the description changes; the name is re-sent unchanged.
        $response = $this->putJson("/api/v1/product-categories/{$category->id}", [
            'name' => 'أكياس',
            'description' => 'وصف جديد',
        ], $headers);

        // Assert
        $response->assertOk()->assertJsonPath('data.description', 'وصف جديد');
    }

    // ─────────────────────────── retiring and removing ───────────────────────────

    public function test_deactivating_hides_a_category_without_touching_its_products(): void
    {
        // Arrange
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);
        $product = Product::factory()->create(['product_category_id' => $category->id]);

        // Act
        $response = $this->patchJson(
            "/api/v1/product-categories/{$category->id}/activation",
            ['is_active' => false],
            $headers,
        );

        // Assert — it leaves the pickers; the product keeps saying what it says.
        $response->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame($category->id, $product->fresh()->product_category_id);
    }

    public function test_a_category_nothing_points_at_can_be_deleted(): void
    {
        // Arrange — the row that should never have existed: a typo, a duplicate.
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'خطأ إملائي']);

        // Act
        $response = $this->deleteJson("/api/v1/product-categories/{$category->id}", [], $headers);

        // Assert — soft, like every delete here: the row and its history survive.
        $response->assertOk();
        $this->assertSoftDeleted('product_categories', ['id' => $category->id]);
    }

    public function test_a_category_a_product_points_at_is_refused(): void
    {
        // Arrange
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);
        Product::factory()->create(['product_category_id' => $category->id]);

        // Act
        $response = $this->deleteJson("/api/v1/product-categories/{$category->id}", [], $headers);

        // Assert — and the message says what to do instead, because "no" on its own is a dead end.
        $response->assertStatus(422);
        $this->assertStringContainsString('أوقفه بدل حذفه', (string) $response->json('message'));
        $this->assertDatabaseHas('product_categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_a_deleted_product_still_holds_its_category_back(): void
    {
        // Arrange — a soft-deleted product can be restored, and a category removed meanwhile
        // would leave it pointing at nothing.
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);
        $product = Product::factory()->create(['product_category_id' => $category->id]);
        $product->delete();

        // Act
        $response = $this->deleteJson("/api/v1/product-categories/{$category->id}", [], $headers);

        // Assert
        $response->assertStatus(422);
    }

    // ─────────────────────────── who may do what ───────────────────────────

    public function test_a_reader_may_list_but_not_change(): void
    {
        // Arrange — no pair of its own: whoever reads products needs the headings to read them
        // by, and whoever maintains products maintains the headings.
        $headers = $this->readerHeaders();
        ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act - Assert
        $this->getJson('/api/v1/product-categories', $headers)->assertOk();
        $this->postJson('/api/v1/product-categories', ['name' => 'جديد'], $headers)->assertForbidden();
    }

    // ─────────────────────────── on the product itself ───────────────────────────

    public function test_a_product_cannot_be_saved_without_a_category(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->post('/api/v1/products', [
            'name' => 'أكياس الشحن',
            'pricing_unit' => 'piece',
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
            'image' => UploadedFile::fake()->image('bag.jpg'),
        ], $headers);

        // Assert — required from today on, whatever the column allows for the rows that predate
        // the feature.
        $response->assertStatus(422)->assertJsonValidationErrors('product_category_id');
    }

    public function test_a_product_carries_its_category_in_the_response(): void
    {
        // Arrange
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act
        $response = $this->post('/api/v1/products', [
            'name' => 'أكياس الشحن',
            'product_category_id' => $category->id,
            'pricing_unit' => 'piece',
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
            'image' => UploadedFile::fake()->image('bag.jpg'),
        ], $headers);

        // Assert — the whole object, so a list draws the heading without a request per row.
        $response->assertCreated()
            ->assertJsonPath('data.product_category.id', $category->id)
            ->assertJsonPath('data.product_category.name', 'أكياس');
    }

    public function test_a_category_that_was_deleted_cannot_be_assigned(): void
    {
        // Arrange — a product pointing at a hidden row is worse than one with none.
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);
        $category->delete();

        // Act
        $response = $this->post('/api/v1/products', [
            'name' => 'أكياس الشحن',
            'product_category_id' => $category->id,
            'pricing_unit' => 'piece',
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
            'image' => UploadedFile::fake()->image('bag.jpg'),
        ], $headers);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('product_category_id');
    }

    public function test_the_products_list_can_be_narrowed_to_one_category(): void
    {
        // Arrange
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);
        $boxes = ProductCategory::factory()->create(['name' => 'علب']);
        Product::factory()->create(['name' => 'كيس ورقي', 'product_category_id' => $bags->id]);
        Product::factory()->create(['name' => 'كرتونة شحن', 'product_category_id' => $boxes->id]);

        // Act
        $response = $this->getJson("/api/v1/products?product_category_id={$bags->id}", $headers);

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'كيس ورقي');
    }

    public function test_an_empty_category_filter_means_every_category(): void
    {
        // Arrange — `?product_category_id=` with nothing after it is «الكل», not «التصنيف رقم صفر».
        $headers = $this->auth();
        Product::factory()->count(2)->create();

        // Act
        $response = $this->getJson('/api/v1/products?product_category_id=', $headers);

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_categorys_history_is_readable(): void
    {
        // Arrange
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);
        $this->putJson("/api/v1/product-categories/{$category->id}", ['name' => 'أكياس ورقية'], $headers)
            ->assertOk();

        // Act
        $response = $this->getJson("/api/v1/product-categories/{$category->id}/logs", $headers);

        // Assert — the project rule: every model soft-deletes and every model has a `/logs`.
        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    // ─────────────────────────── the tree ───────────────────────────

    public function test_a_heading_can_be_filed_under_another(): void
    {
        // Arrange
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act
        $response = $this->postJson('/api/v1/product-categories', [
            'name' => 'أكياس ورقية',
            'parent_id' => $bags->id,
        ], $headers);

        // Assert
        $response->assertCreated()->assertJsonPath('data.parent_id', $bags->id);
    }

    public function test_the_tree_stops_at_one_level(): void
    {
        // Arrange — nothing in the catalogue is three deep, and a tree of arbitrary depth costs
        // every screen a recursive render for a shape nobody asked for.
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);
        $paper = ProductCategory::factory()->create([
            'name' => 'أكياس ورقية',
            'parent_id' => $bags->id,
        ]);

        // Act
        $response = $this->postJson('/api/v1/product-categories', [
            'name' => 'أكياس ورقية مقواة',
            'parent_id' => $paper->id,
        ], $headers);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_a_heading_cannot_be_filed_under_itself(): void
    {
        // Arrange — `exists` would happily accept its own id; only the row knows better.
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act
        $response = $this->putJson("/api/v1/product-categories/{$bags->id}", [
            'name' => 'أكياس',
            'parent_id' => $bags->id,
        ], $headers);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_a_heading_holding_children_cannot_become_a_child(): void
    {
        // Arrange — it would make its children grandchildren of a root, quietly breaking the
        // one-level rule the rest of the codebase relies on.
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);
        ProductCategory::factory()->create(['name' => 'أكياس ورقية', 'parent_id' => $bags->id]);
        $boxes = ProductCategory::factory()->create(['name' => 'علب']);

        // Act
        $response = $this->putJson("/api/v1/product-categories/{$bags->id}", [
            'name' => 'أكياس',
            'parent_id' => $boxes->id,
        ], $headers);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_a_product_cannot_be_filed_under_a_heading_that_has_children(): void
    {
        // Arrange — a heading with subheadings is a heading, not a slot.
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);
        ProductCategory::factory()->create(['name' => 'أكياس ورقية', 'parent_id' => $bags->id]);

        // Act
        $response = $this->post('/api/v1/products', [
            'name' => 'كيس',
            'product_category_id' => $bags->id,
            'pricing_unit' => 'piece',
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
            'image' => UploadedFile::fake()->image('bag.jpg'),
        ], $headers);

        // Assert — and the message names the way out rather than merely refusing.
        $response->assertStatus(422)->assertJsonValidationErrors('product_category_id');
        $this->assertStringContainsString(
            'اختر أحد فروعه',
            (string) $response->json('errors.product_category_id.0'),
        );
    }

    public function test_a_parent_counts_what_is_under_its_children(): void
    {
        // Arrange
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);
        $paper = ProductCategory::factory()->create([
            'name' => 'أكياس ورقية',
            'parent_id' => $bags->id,
        ]);
        Product::factory()->count(3)->create(['product_category_id' => $paper->id]);

        // Act
        $response = $this->getJson('/api/v1/product-categories', $headers);

        // Assert — «أكياس · ٣ منتجات» is what a screen shows; the row holds none of its own.
        $parent = collect($response->json('data'))->firstWhere('name', 'أكياس');
        $this->assertSame(0, $parent['products_count']);
        $this->assertSame(1, $parent['children_count']);
        $this->assertSame(3, $parent['total_products_count']);
    }

    public function test_filtering_products_by_a_parent_returns_what_is_under_its_children(): void
    {
        // Arrange
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);
        $paper = ProductCategory::factory()->create([
            'name' => 'أكياس ورقية',
            'parent_id' => $bags->id,
        ]);
        Product::factory()->create(['name' => 'كيس ورقي', 'product_category_id' => $paper->id]);
        Product::factory()->create(['name' => 'كرتونة']);

        // Act
        $response = $this->getJson("/api/v1/products?product_category_id={$bags->id}", $headers);

        // Assert — matching only the row itself would answer «لا منتجات» for a full heading.
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'كيس ورقي');
    }

    public function test_a_picker_asks_for_the_headings_a_product_may_be_filed_under(): void
    {
        // Arrange
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);
        ProductCategory::factory()->create(['name' => 'أكياس ورقية', 'parent_id' => $bags->id]);
        ProductCategory::factory()->create(['name' => 'ستيكرات']);

        // Act
        $response = $this->getJson('/api/v1/product-categories?leaf_only=1', $headers);

        // Assert — the parent is absent; its child and the childless heading are not.
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertNotContains('أكياس', $names);
        $this->assertContains('أكياس ورقية', $names);
        $this->assertContains('ستيكرات', $names);
    }

    public function test_a_child_behind_a_stopped_parent_is_not_offered(): void
    {
        // Arrange — the root was stopped precisely to take that part of the catalogue out of
        // circulation; honouring only the child's own flag would apply half the decision.
        $headers = $this->auth();
        $bags = ProductCategory::factory()->inactive()->create(['name' => 'أكياس']);
        ProductCategory::factory()->create(['name' => 'أكياس ورقية', 'parent_id' => $bags->id]);

        // Act
        $response = $this->getJson('/api/v1/product-categories?is_active=1&leaf_only=1', $headers);

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_heading_holding_children_is_not_deletable(): void
    {
        // Arrange — deleting is soft, so a parent removed from under its children would leave
        // them pointing at a row the API no longer returns.
        $headers = $this->auth();
        $bags = ProductCategory::factory()->create(['name' => 'أكياس']);
        ProductCategory::factory()->create(['name' => 'أكياس ورقية', 'parent_id' => $bags->id]);

        // Act
        $response = $this->deleteJson("/api/v1/product-categories/{$bags->id}", [], $headers);

        // Assert
        $response->assertStatus(422);
        $this->assertStringContainsString('التصنيفات الفرعية', (string) $response->json('message'));
    }

    // ─────────────────────────── the order ───────────────────────────

    public function test_the_whole_order_is_saved_in_one_call(): void
    {
        // Arrange
        $headers = $this->auth();
        $first = ProductCategory::factory()->create(['name' => 'أكياس', 'sort_order' => 1]);
        $second = ProductCategory::factory()->create(['name' => 'علب', 'sort_order' => 2]);
        $third = ProductCategory::factory()->create(['name' => 'ستيكرات', 'sort_order' => 3]);

        // Act — dragged into the reverse order.
        $response = $this->patchJson('/api/v1/product-categories/order', [
            'ids' => [$third->id, $second->id, $first->id],
        ], $headers);

        // Assert
        $response->assertOk();
        $this->getJson('/api/v1/product-categories', $headers)
            ->assertJsonPath('data.0.name', 'ستيكرات')
            ->assertJsonPath('data.1.name', 'علب')
            ->assertJsonPath('data.2.name', 'أكياس');
    }

    public function test_a_heading_left_out_of_the_order_keeps_its_place(): void
    {
        // Arrange — a screen showing one page must be able to reorder that page without
        // claiming anything about the rest.
        $headers = $this->auth();
        $untouched = ProductCategory::factory()->create(['name' => 'ستيكرات', 'sort_order' => 900]);
        $first = ProductCategory::factory()->create(['name' => 'أكياس', 'sort_order' => 1]);
        $second = ProductCategory::factory()->create(['name' => 'علب', 'sort_order' => 2]);

        // Act
        $this->patchJson('/api/v1/product-categories/order', [
            'ids' => [$second->id, $first->id],
        ], $headers)->assertOk();

        // Assert
        $this->assertSame(900, $untouched->fresh()->sort_order);
    }

    public function test_the_same_heading_twice_in_an_order_is_refused(): void
    {
        // Arrange — the second write would decide the row's position, and a list that came back
        // in an order nobody dragged reads as the drag having failed.
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act
        $response = $this->patchJson('/api/v1/product-categories/order', [
            'ids' => [$category->id, $category->id],
        ], $headers);

        // Assert
        $response->assertStatus(422);
    }

    public function test_reordering_needs_the_permission_that_changes_the_catalogue(): void
    {
        // Arrange
        $headers = $this->readerHeaders();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act
        $response = $this->patchJson(
            '/api/v1/product-categories/order',
            ['ids' => [$category->id]],
            $headers,
        );

        // Assert
        $response->assertForbidden();
    }

    // ─────────────────────────── the picture ───────────────────────────

    public function test_a_heading_can_be_given_a_picture(): void
    {
        // Arrange
        Storage::fake('public');
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act
        $response = $this->post(
            "/api/v1/product-categories/{$category->id}/image",
            ['image' => UploadedFile::fake()->image('bags.jpg', 800, 600)],
            $headers,
        );

        // Assert — the URL is built from the disk the file went to, never stored.
        $response->assertOk()
            ->assertJsonPath('data.image_width_px', 800)
            ->assertJsonPath('data.image_height_px', 600);
        $this->assertNotNull($response->json('data.image_url'));

        Storage::disk('public')->assertExists((string) $category->fresh()->image_path);
    }

    public function test_replacing_a_picture_removes_the_one_it_replaced(): void
    {
        // Arrange — nothing points at a heading's picture the way an order points at a design,
        // so keeping every replaced one would grow without bound for no reader.
        Storage::fake('public');
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);

        $this->post(
            "/api/v1/product-categories/{$category->id}/image",
            ['image' => UploadedFile::fake()->image('first.jpg')],
            $headers,
        )->assertOk();
        $first = (string) $category->fresh()->image_path;

        // Act
        $this->post(
            "/api/v1/product-categories/{$category->id}/image",
            ['image' => UploadedFile::fake()->image('second.jpg')],
            $headers,
        )->assertOk();

        // Assert
        $second = (string) $category->fresh()->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        // Arrange
        Storage::fake('public');
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act
        $response = $this->post(
            "/api/v1/product-categories/{$category->id}/image",
            ['image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
            $headers,
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_removing_a_picture_twice_is_not_an_error(): void
    {
        // Arrange — a second tap after a dropped connection must not be a failure.
        Storage::fake('public');
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);

        $this->post(
            "/api/v1/product-categories/{$category->id}/image",
            ['image' => UploadedFile::fake()->image('bags.jpg')],
            $headers,
        )->assertOk();
        $path = (string) $category->fresh()->image_path;

        // Act
        $this->deleteJson("/api/v1/product-categories/{$category->id}/image", [], $headers)
            ->assertOk();
        $response = $this->deleteJson("/api/v1/product-categories/{$category->id}/image", [], $headers);

        // Assert
        $response->assertOk()->assertJsonPath('data.image_url', null);
        Storage::disk('public')->assertMissing($path);
    }

    // ─────────── طريقة التنفيذ، والمفتاح القديم الذي ما زال التطبيق يكتبه ───────────
    // The boolean `skips_production` became a three-valued `production_mode`, and the shipped app
    // still speaks the boolean in both directions — see OUTSOURCED-PRODUCTS.md §2 and §8.

    public function test_a_heading_can_be_marked_as_made_by_an_outside_vendor(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->postJson('/api/v1/product-categories', [
            'name' => 'وسيط',
            'production_mode' => 'outsourced',
        ], $headers);

        // Assert — the mode round-trips with its Arabic, so a screen names it without keeping a
        // dictionary of its own.
        $response->assertCreated()
            ->assertJsonPath('data.production_mode', 'outsourced')
            ->assertJsonPath('data.production_mode_label', 'وسيط — لدى مورد خارجي');
    }

    public function test_a_heading_nobody_decided_about_is_made_here(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->postJson('/api/v1/product-categories', ['name' => 'أكياس'], $headers);

        // Assert — the road every order took before any of this existed.
        $response->assertCreated()->assertJsonPath('data.production_mode', 'in_house');
    }

    public function test_the_old_boolean_still_says_what_it_always_said(): void
    {
        // Arrange — a build that has never heard of `production_mode`.
        $headers = $this->auth();

        // Act
        $response = $this->postJson('/api/v1/product-categories', [
            'name' => 'سادة',
            'skips_production' => true,
        ], $headers);

        // Assert — «سادة», and the key it was sent under still comes back, for the card that
        // prints «بدون طباعة».
        $response->assertCreated()
            ->assertJsonPath('data.production_mode', 'none')
            ->assertJsonPath('data.skips_production', true);
    }

    public function test_an_old_build_cannot_demote_a_heading_it_has_no_words_for(): void
    {
        // Arrange — «وسيط», then an edit from a build whose sheet holds only a switch, sending
        // that switch off because it has no way to describe what it is looking at.
        $headers = $this->auth();
        $category = ProductCategory::factory()->outsourced()->create(['name' => 'وسيط']);

        // Act
        $response = $this->putJson("/api/v1/product-categories/{$category->id}", [
            'name' => 'وسيط',
            'skips_production' => false,
        ], $headers);

        // Assert — left exactly as it was. Reading that `false` as «مطبوعة» would quietly take
        // every order taken afterwards off the vendor road, cost prices and all.
        $response->assertOk()->assertJsonPath('data.production_mode', 'outsourced');
        $this->assertSame(ProductionMode::Outsourced, $category->fresh()->production_mode);
    }

    public function test_an_edit_that_says_nothing_about_the_mode_changes_nothing(): void
    {
        // Arrange
        $headers = $this->auth();
        $category = ProductCategory::factory()->skipsProduction()->create(['name' => 'سادة']);

        // Act — a rename, and not a word about roads.
        $response = $this->putJson(
            "/api/v1/product-categories/{$category->id}",
            ['name' => 'سادة ورقية'],
            $headers,
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.production_mode', 'none');
    }

    public function test_a_mode_nobody_has_heard_of_is_refused(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->postJson('/api/v1/product-categories', [
            'name' => 'تصنيف',
            'production_mode' => 'subcontracted',
        ], $headers);

        // Assert — a closed set, so the column stays something a report can group by rather than
        // four spellings of one idea.
        $response->assertStatus(422)->assertJsonValidationErrors('production_mode');
    }

    // ─────────────────────────── قابل للاستثمار ───────────────────────────
    // The flag a deal's shelves are checked against — see `ProductCategory::isInvestable()` and
    // `CatalogService::stockItemInvestability()`. Three-valued and inherited downwards, so «لا»
    // and «اسأل أبي» stay two different answers.

    public function test_a_heading_can_be_marked_investable(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->postJson('/api/v1/product-categories', [
            'name' => 'أكياس',
            'is_investable' => true,
        ], $headers);

        // Assert
        $response->assertCreated()->assertJsonPath('data.is_investable', true);
        $this->assertTrue(ProductCategory::query()->where('name', 'أكياس')->sole()->isInvestable());
    }

    public function test_a_heading_nobody_decided_about_is_not_investable(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->postJson('/api/v1/product-categories', ['name' => 'ستيكرات'], $headers);

        // Assert — fail-closed, and null rather than false: «لم يُسأل عني» is what lets a
        // subheading added later inherit an answer instead of carrying a no nobody gave.
        $response->assertCreated()->assertJsonPath('data.is_investable', null);
        $this->assertFalse(ProductCategory::query()->where('name', 'ستيكرات')->sole()->isInvestable());
    }

    public function test_a_subheading_takes_its_parents_answer(): void
    {
        // Arrange
        $headers = $this->auth();
        $parent = ProductCategory::factory()->investable()->create(['name' => 'أكياس']);

        // Act
        $response = $this->postJson('/api/v1/product-categories', [
            'name' => 'أكياس ورقية',
            'parent_id' => $parent->getKey(),
        ], $headers);

        // Assert — null on the row and true when asked. The resource sends this row's own
        // answer, exactly as `production_mode` does: it is the value the edit sheet puts back,
        // and sending the inherited one would have a rename write it onto a child that never
        // asked for it.
        $response->assertCreated()->assertJsonPath('data.is_investable', null);
        $this->assertTrue(ProductCategory::query()->where('name', 'أكياس ورقية')->sole()->isInvestable());
    }

    public function test_a_subheading_can_be_kept_out_of_an_investable_family(): void
    {
        // Arrange — «أكياس» is invested in; the shop's own bags under it are not for sale.
        $headers = $this->auth();
        $parent = ProductCategory::factory()->investable()->create(['name' => 'أكياس']);

        // Act
        $response = $this->postJson('/api/v1/product-categories', [
            'name' => 'أكياس خاصة بالشركة',
            'parent_id' => $parent->getKey(),
            'is_investable' => false,
        ], $headers);

        // Assert — the whole reason the column is nullable: a boolean defaulting to false could
        // not tell this row apart from one nobody had asked about, and the family's yes would
        // have reached it.
        $response->assertCreated()->assertJsonPath('data.is_investable', false);
        $this->assertFalse(
            ProductCategory::query()->where('name', 'أكياس خاصة بالشركة')->sole()->isInvestable(),
        );
    }

    public function test_an_edit_that_says_nothing_about_investability_changes_nothing(): void
    {
        // Arrange — a build whose sheet has never heard of the flag, renaming a funded heading.
        $headers = $this->auth();
        $category = ProductCategory::factory()->investable()->create(['name' => 'أكياس']);

        // Act
        $response = $this->putJson(
            "/api/v1/product-categories/{$category->id}",
            ['name' => 'أكياس شحن'],
            $headers,
        );

        // Assert — read as «لا» instead, a rename from an old build would close every shelf
        // under the heading to new deals, and nothing on any screen would say why.
        $response->assertOk()->assertJsonPath('data.is_investable', true);
        $this->assertTrue($category->fresh()->is_investable);
    }

    public function test_the_answer_can_be_handed_back_to_the_parent(): void
    {
        // Arrange
        $headers = $this->auth();
        $parent = ProductCategory::factory()->investable()->create(['name' => 'أكياس']);
        $child = ProductCategory::factory()->create([
            'name' => 'أكياس ورقية',
            'parent_id' => $parent->getKey(),
            'is_investable' => false,
        ]);

        // Act — «ورّث» is an answer, not the absence of one, so it travels as an explicit null.
        // `parent_id` travels too: a PUT replaces the whole representation, and a subheading
        // whose parent is left out of one becomes a root — which would make «ورّث» mean «لا».
        $response = $this->putJson("/api/v1/product-categories/{$child->id}", [
            'name' => 'أكياس ورقية',
            'parent_id' => $parent->getKey(),
            'is_investable' => null,
        ], $headers);

        // Assert
        $response->assertOk()->assertJsonPath('data.is_investable', null);
        $this->assertNull($child->fresh()->is_investable);
        $this->assertTrue($child->fresh()->isInvestable());
    }

    public function test_an_edit_that_says_nothing_about_the_parent_leaves_it_where_it_is_filed(): void
    {
        // Arrange — the build already in people's hands: a rename carrying a name and nothing
        // else, on a subheading inside an investable family.
        $headers = $this->auth();
        $parent = ProductCategory::factory()->investable()->create(['name' => 'أكياس']);
        $child = ProductCategory::factory()->create([
            'name' => 'أكياس ورقية',
            'parent_id' => $parent->getKey(),
        ]);

        // Act
        $response = $this->putJson(
            "/api/v1/product-categories/{$child->id}",
            ['name' => 'أكياس ورقية مقواة'],
            $headers,
        );

        // Assert — rooted instead, it would stop inheriting its family's yes and answer «لا»,
        // closing every shelf under it to new deals on a request that only changed a name. The
        // twin of the guard `is_investable` already has, for the same client.
        $response->assertOk()->assertJsonPath('data.parent_id', $parent->getKey());
        $this->assertSame($parent->getKey(), $child->fresh()->parent_id);
        $this->assertTrue($child->fresh()->isInvestable());
    }

    public function test_an_edit_that_says_nothing_about_a_field_keeps_what_is_stored(): void
    {
        // Arrange — a stopped heading, sixth in the catalogue, edited by a client that sends
        // only what it meant to change.
        $headers = $this->auth();
        $category = ProductCategory::factory()->inactive()->create([
            'name' => 'ستيكرات',
            'sort_order' => 6,
        ]);

        // Act
        $response = $this->putJson(
            "/api/v1/product-categories/{$category->id}",
            ['name' => 'ستيكرات ومطبوعات'],
            $headers,
        );

        // Assert — one rule for the whole row, not for the two fields this feature happened to
        // touch: re-offering a heading somebody stopped, and moving it to the top of the
        // catalogue, are two decisions nobody took.
        $response->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.sort_order', 6);
    }

    public function test_a_subheading_can_still_be_made_a_heading_of_its_own(): void
    {
        // Arrange
        $headers = $this->auth();
        $parent = ProductCategory::factory()->create(['name' => 'أكياس']);
        $child = ProductCategory::factory()->create([
            'name' => 'أكياس ورقية',
            'parent_id' => $parent->getKey(),
        ]);

        // Act — now that an absent parent means «اتركه كما هو», promotion has to be said.
        $response = $this->putJson("/api/v1/product-categories/{$child->id}", [
            'name' => 'أكياس ورقية',
            'parent_id' => null,
        ], $headers);

        // Assert
        $response->assertOk()->assertJsonPath('data.parent_id', null);
        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_opening_a_heading_to_investment_is_written_to_its_history(): void
    {
        // Arrange
        $headers = $this->auth();
        $category = ProductCategory::factory()->create(['name' => 'أكياس']);

        // Act
        $this->putJson("/api/v1/product-categories/{$category->id}", [
            'name' => 'أكياس',
            'is_investable' => true,
        ], $headers)->assertOk();

        // Assert — who opened a heading to investor money, and when, is exactly what the log is
        // for: every shelf under it becomes fundable the moment this is saved.
        //
        // The *value* is what is asserted, not the label beside it: the creation entry names
        // every column of the new row, `is_investable` among them, so a label alone would be
        // there whether the edit had landed or not.
        $this->getJson("/api/v1/product-categories/{$category->id}/logs", $headers)
            ->assertOk()
            ->assertJsonFragment(['is_investable' => true]);
    }
}
