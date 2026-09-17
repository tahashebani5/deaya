<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Saying which product sizes draw on one material, from the material's side.
 *
 * **The link has only ever had one writer, and it was the product.** `product_variants
 * .stock_item_id` is set by `POST|PUT /products` — explicitly in the payload, or resolved from
 * the product's category — so pointing four sizes across three products at one pile meant
 * editing three products, each time sending prices, tiers and images that the person doing the
 * pointing had no business rewriting.
 *
 * This endpoint is the other direction, and it is **a replacement, not an addition**: the list it
 * is given becomes true. What is in it is linked, what is missing is unlinked, and an empty list
 * empties the material deliberately. That is what a multi-select means when it is saved, and it
 * is the only shape that gives unlinking a home.
 *
 * **Moving a size off another material is allowed here.** Past movements do not follow it — they
 * are keyed on the material, not on the size — so what changes is only what this size deducts
 * from next. The screen confirms it by name first; the server does not, because a caller who
 * says «these four» has said it.
 *
 * Arrange - Act - Assert throughout.
 */
class StockItemVariantsTest extends TestCase
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
    private function manager(): array
    {
        return $this->auth(PermissionName::ViewInventory, PermissionName::ManageInventory);
    }

    /** @return array<string, string> */
    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewInventory);
    }

    /** A size of a product, linked to nothing unless told otherwise. */
    private function variant(?StockItem $item = null): ProductVariant
    {
        return ProductVariant::factory()
            ->for(Product::factory())
            ->create(['stock_item_id' => $item?->getKey()]);
    }

    public function test_a_manager_points_several_sizes_at_one_material(): void
    {
        // Arrange — three sizes across three products, none of them filed anywhere.
        $item = StockItem::factory()->create();
        $first = $this->variant();
        $second = $this->variant();
        $third = $this->variant();

        // Act
        $response = $this->withHeaders($this->manager())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => [$first->getKey(), $second->getKey()]],
        );

        // Assert
        $response->assertOk();
        $this->assertSame($item->getKey(), $first->refresh()->stock_item_id);
        $this->assertSame($item->getKey(), $second->refresh()->stock_item_id);
        $this->assertNull($third->refresh()->stock_item_id, 'a size nobody named must stay loose');
    }

    public function test_the_list_replaces_rather_than_adds(): void
    {
        // Arrange — two sizes already drawing on it, and only one of them named this time.
        $item = StockItem::factory()->create();
        $kept = $this->variant($item);
        $dropped = $this->variant($item);

        // Act
        $response = $this->withHeaders($this->manager())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => [$kept->getKey()]],
        );

        // Assert — the one left out comes off, which is the whole meaning of unticking it.
        $response->assertOk();
        $this->assertSame($item->getKey(), $kept->refresh()->stock_item_id);
        $this->assertNull($dropped->refresh()->stock_item_id);
    }

    public function test_an_empty_list_empties_the_material(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $variant = $this->variant($item);

        // Act
        $response = $this->withHeaders($this->manager())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => []],
        );

        // Assert — deliberate, not a missing key: «لا شيء يسحب من هذه المادة» is a real answer.
        $response->assertOk();
        $this->assertNull($variant->refresh()->stock_item_id);
    }

    public function test_a_size_drawing_on_another_material_is_moved(): void
    {
        // Arrange
        $elsewhere = StockItem::factory()->create();
        $item = StockItem::factory()->create();
        $variant = $this->variant($elsewhere);

        // Act
        $response = $this->withHeaders($this->manager())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => [$variant->getKey()]],
        );

        // Assert
        $response->assertOk();
        $this->assertSame($item->getKey(), $variant->refresh()->stock_item_id);
    }

    public function test_the_answer_carries_the_material_and_its_sizes(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $variant = $this->variant();

        // Act
        $response = $this->withHeaders($this->manager())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => [$variant->getKey()]],
        );

        // Assert — the screen redraws from the server rather than from what it hoped it sent.
        $response->assertOk()
            ->assertJsonPath('data.id', $item->getKey())
            ->assertJsonPath('data.variants_count', 1)
            ->assertJsonPath('data.variants.0.id', $variant->getKey());
    }

    public function test_every_moved_link_is_written_to_the_audit_trail(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $variant = $this->variant();

        // Act
        $this->withHeaders($this->manager())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => [$variant->getKey()]],
        )->assertOk();

        // Assert — saved one model at a time for exactly this: a mass update fires no events, and
        // a size quietly changing which pile it eats from is the last thing to leave unrecorded.
        $this->assertDatabaseHas('activity_log', [
            // The morph alias, which is what `AuditSubject` maps and what a log reader filters by.
            'subject_type' => 'product_variant',
            'subject_id' => $variant->getKey(),
            'event' => 'updated',
        ]);
    }

    public function test_an_unknown_size_is_refused_and_nothing_moves(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $variant = $this->variant($item);

        // Act
        $response = $this->withHeaders($this->manager())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => [$variant->getKey(), 999_999]],
        );

        // Assert — refused whole: half a selection stored is worse than none, because nothing on
        // the screen would say which half.
        $response->assertStatus(422)->assertJsonValidationErrors('variant_ids.1');
        $this->assertSame($item->getKey(), $variant->refresh()->stock_item_id);
    }

    public function test_a_deleted_size_is_refused(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $variant = $this->variant();
        $variant->delete();

        // Act
        $response = $this->withHeaders($this->manager())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => [$variant->getKey()]],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('variant_ids.0');
    }

    public function test_the_same_size_twice_is_refused(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $variant = $this->variant();

        // Act
        $response = $this->withHeaders($this->manager())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => [$variant->getKey(), $variant->getKey()]],
        );

        // Assert
        $response->assertStatus(422);
    }

    public function test_a_reader_may_not_move_links(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $variant = $this->variant();

        // Act
        $response = $this->withHeaders($this->viewer())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => [$variant->getKey()]],
        );

        // Assert — the boundary is the route's `can:`, not the screen that hides the control.
        $response->assertForbidden();
        $this->assertNull($variant->refresh()->stock_item_id);
    }

    public function test_a_deleted_material_is_not_found(): void
    {
        // Arrange
        $item = StockItem::factory()->create();
        $item->delete();

        // Act
        $response = $this->withHeaders($this->manager())->putJson(
            "/api/v1/stock-items/{$item->getKey()}/variants",
            ['variant_ids' => []],
        );

        // Assert
        $response->assertNotFound();
    }
}
