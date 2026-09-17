<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Models\ManufacturingCostRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What a unit of production standard-costs at. Two permissions guard it, the same split
 * `purchase_orders.*` draws between paperwork and the ledger it feeds: reading is common, curating
 * the table is rarer.
 *
 * Arrange - Act - Assert throughout.
 */
class ManufacturingCostRateTest extends TestCase
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

    private function viewer(): array
    {
        return $this->auth(PermissionName::ViewManufacturingCostRates);
    }

    private function manager(): array
    {
        return $this->auth(PermissionName::ViewManufacturingCostRates, PermissionName::ManageManufacturingCostRates);
    }

    // ─────────────────────────── reading ───────────────────────────

    public function test_reading_the_list_needs_the_view_permission(): void
    {
        // Arrange
        ManufacturingCostRate::factory()->default()->create();
        $headers = $this->auth(PermissionName::ViewOrders);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/manufacturing-cost-rates');

        // Assert
        $response->assertForbidden();
    }

    public function test_the_list_can_be_filtered_by_cost_type_and_activity(): void
    {
        // Arrange
        ManufacturingCostRate::factory()->default()->type(ManufacturingCostType::Labor)->create();
        ManufacturingCostRate::factory()->default()->type(ManufacturingCostType::Overhead)->inactive()->create();

        // Act
        $response = $this->withHeaders($this->viewer())
            ->getJson('/api/v1/manufacturing-cost-rates?cost_type=overhead&is_active=0');

        // Assert
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('overhead', $response->json('data.0.cost_type'));
    }

    // ─────────────────────────── writing ───────────────────────────

    public function test_a_default_rate_can_be_created_without_a_product(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/manufacturing-cost-rates', [
            'cost_type' => 'labor',
            'rate_per_unit' => 3.5,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.product', null)
            ->assertJsonPath('data.cost_type', 'labor')
            ->assertJsonPath('data.rate_per_unit', '3.500')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('manufacturing_cost_rates', [
            'product_id' => null, 'cost_type' => 'labor', 'rate_per_unit' => '3.500',
        ]);
    }

    public function test_a_product_specific_rate_can_be_created(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/manufacturing-cost-rates', [
            'product_id' => $product->id,
            'cost_type' => 'overhead',
            'rate_per_unit' => 1.25,
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.product.id', $product->id);
    }

    public function test_only_one_default_rate_per_cost_type_is_allowed(): void
    {
        // Arrange
        ManufacturingCostRate::factory()->default()->type(ManufacturingCostType::Labor)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/manufacturing-cost-rates', [
            'cost_type' => 'labor',
            'rate_per_unit' => 9,
        ]);

        // Assert — a readable 422, not a constraint violation surfacing as a 500
        $response->assertStatus(422)->assertJsonValidationErrors('cost_type');
    }

    public function test_only_one_rate_per_product_and_cost_type_is_allowed(): void
    {
        // Arrange
        $product = Product::factory()->create();
        ManufacturingCostRate::factory()->forProduct($product)->type(ManufacturingCostType::Labor)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/manufacturing-cost-rates', [
            'product_id' => $product->id,
            'cost_type' => 'labor',
            'rate_per_unit' => 9,
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('cost_type');
    }

    public function test_a_product_may_have_a_rate_for_each_different_cost_type(): void
    {
        // Arrange — the composite key is (product, cost type), not product alone
        $product = Product::factory()->create();
        ManufacturingCostRate::factory()->forProduct($product)->type(ManufacturingCostType::Labor)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/manufacturing-cost-rates', [
            'product_id' => $product->id,
            'cost_type' => 'overhead',
            'rate_per_unit' => 2,
        ]);

        // Assert
        $response->assertCreated();
    }

    public function test_a_variant_specific_rate_can_be_created(): void
    {
        // Arrange
        $variant = ProductVariant::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/manufacturing-cost-rates', [
            'product_variant_id' => $variant->id,
            'cost_type' => 'labor',
            'rate_per_unit' => 6,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.product', null)
            ->assertJsonPath('data.product_variant.id', $variant->id);
    }

    public function test_only_one_rate_per_variant_and_cost_type_is_allowed(): void
    {
        // Arrange
        $variant = ProductVariant::factory()->create();
        ManufacturingCostRate::factory()->forVariant($variant)->type(ManufacturingCostType::Labor)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/manufacturing-cost-rates', [
            'product_variant_id' => $variant->id,
            'cost_type' => 'labor',
            'rate_per_unit' => 9,
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('cost_type');
    }

    public function test_a_variant_specific_rate_coexists_with_its_products_own_rate(): void
    {
        // Arrange — the same product also carries a product-wide rate for the same cost type;
        // the two are different tiers and do not collide.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        ManufacturingCostRate::factory()->forProduct($product)->type(ManufacturingCostType::Labor)->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/manufacturing-cost-rates', [
            'product_variant_id' => $variant->id,
            'cost_type' => 'labor',
            'rate_per_unit' => 9,
        ]);

        // Assert
        $response->assertCreated();
    }

    public function test_naming_both_a_product_and_a_variant_is_refused(): void
    {
        // Arrange — ambiguous about which of the three tiers the row belongs to
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/manufacturing-cost-rates', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'cost_type' => 'labor',
            'rate_per_unit' => 9,
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('product_variant_id');
        $this->assertDatabaseCount('manufacturing_cost_rates', 0);
    }

    public function test_a_rate_can_be_updated(): void
    {
        // Arrange
        $rate = ManufacturingCostRate::factory()->default()->type(ManufacturingCostType::Labor)->rate('3.000')->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/manufacturing-cost-rates/{$rate->id}", [
            'cost_type' => 'labor',
            'rate_per_unit' => 4.75,
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.rate_per_unit', '4.750');
    }

    public function test_a_rate_can_be_deactivated(): void
    {
        // Arrange
        $rate = ManufacturingCostRate::factory()->default()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/manufacturing-cost-rates/{$rate->id}/activation", ['is_active' => false]);

        // Assert
        $response->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_writing_needs_the_manage_permission(): void
    {
        // Arrange
        $rate = ManufacturingCostRate::factory()->default()->create();
        $headers = $this->viewer();

        // Act
        $create = $this->withHeaders($headers)->postJson('/api/v1/manufacturing-cost-rates', [
            'cost_type' => 'overhead', 'rate_per_unit' => 1,
        ]);
        $delete = $this->withHeaders($headers)->deleteJson("/api/v1/manufacturing-cost-rates/{$rate->id}");

        // Assert
        $create->assertForbidden();
        $delete->assertForbidden();
    }

    // ─────────────────────────── deleting ───────────────────────────

    public function test_a_rate_can_be_deleted_even_though_orders_have_used_it(): void
    {
        // Arrange — nothing points at a rate by foreign key; an applied rate is snapshotted onto
        // its production_cost_entries row instead, so there is no in-use guard to test here.
        $rate = ManufacturingCostRate::factory()->default()->create();

        // Act
        $response = $this->withHeaders($this->manager())->deleteJson("/api/v1/manufacturing-cost-rates/{$rate->id}");

        // Assert
        $response->assertOk();
        $this->assertSoftDeleted('manufacturing_cost_rates', ['id' => $rate->id]);
    }

    public function test_a_deleted_rates_slot_can_be_reused(): void
    {
        // Arrange
        $rate = ManufacturingCostRate::factory()->default()->type(ManufacturingCostType::Labor)->create();
        $this->withHeaders($this->manager())->deleteJson("/api/v1/manufacturing-cost-rates/{$rate->id}");

        // Act
        $response = $this->withHeaders($this->manager())->postJson('/api/v1/manufacturing-cost-rates', [
            'cost_type' => 'labor', 'rate_per_unit' => 5,
        ]);

        // Assert — the unique index is partial for exactly this reason
        $response->assertCreated();
    }
}
