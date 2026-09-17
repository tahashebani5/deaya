<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Catalog\Actions\SyncProductVariants;
use App\Domain\Catalog\DTOs\PriceTierData;
use App\Domain\Catalog\DTOs\ProductVariantData;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Actions\SyncCustomerShops;
use App\Domain\Customer\DTOs\CustomerShopData;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerShop;
use App\Domain\Delivery\DeliveryService;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What soft deleting actually changes.
 *
 * Three things have to hold, and each of them broke something that used to work:
 *
 * 1. A deleted record leaves the API but keeps its row.
 * 2. Deleting a parent still takes its children — the foreign key's `cascadeOnDelete` no longer
 *    fires, because nothing is deleted any more.
 * 3. A deleted record releases the name it held, so it can be created again — the partial unique
 *    indexes, and the `withoutTrashed()` on every uniqueness rule that has to agree with them.
 *
 * Arrange - Act - Assert throughout.
 */
class SoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────── the row survives ───────────────────────────

    public function test_a_deleted_record_disappears_from_queries_but_keeps_its_row(): void
    {
        // Arrange
        $city = City::factory()->create();

        // Act
        $city->delete();

        // Assert
        $this->assertNull(City::query()->find($city->id));
        $this->assertNotNull(City::withTrashed()->find($city->id));
        $this->assertDatabaseHas('cities', ['id' => $city->id]);
    }

    public function test_a_deleted_record_can_be_brought_back(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'هون']);
        $city->delete();

        // Act
        $city->restore();

        // Assert
        $restored = City::query()->find($city->id);
        $this->assertNotNull($restored);
        $this->assertSame('هون', $restored->name);
    }

    public function test_a_deleted_user_can_no_longer_authenticate(): void
    {
        // Arrange
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        $user->delete();
        $this->app->get('auth')->forgetGuards();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/auth/me');

        // Assert — nothing checks for this explicitly. The global scope on the model is what
        // makes Sanctum's token lookup resolve to nobody.
        $response->assertStatus(401)->assertJsonPath('status', false);
    }

    // ─────────────────────────── the cascade moved into code ───────────────────────────

    public function test_deleting_a_city_takes_its_regions_with_it(): void
    {
        // Arrange
        $city = City::factory()->create();
        $regions = Region::factory()->count(3)->create(['city_id' => $city->id]);

        // Act
        app(DeliveryService::class)->deleteCity($city);

        // Assert — the foreign key's cascade never fires for a soft delete, so this only holds
        // because DeleteCity does it explicitly.
        $this->assertSame(0, Region::query()->where('city_id', $city->id)->count());
        $this->assertSame(3, Region::withTrashed()->where('city_id', $city->id)->count());
        $this->assertNotNull($regions->first()?->fresh()?->deleted_at);
    }

    public function test_deleting_a_city_records_each_region_it_removed(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->count(2)->create(['city_id' => $city->id]);

        // Act
        app(DeliveryService::class)->deleteCity($city);

        // Assert — a bulk update would have removed them silently, leaving the history with a
        // gap exactly where someone would look.
        $this->assertSame(2, ActivityLog::query()
            ->where('subject_type', 'region')
            ->where('event', 'deleted')
            ->count());
    }

    public function test_dropping_a_variant_from_a_product_takes_its_price_tiers(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $keep = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => '25*35']);
        $drop = ProductVariant::factory()->create(['product_id' => $product->id, 'label' => '35*40']);
        ProductPriceTier::factory()->count(2)->create(['product_variant_id' => $drop->id]);

        // Act
        app(SyncProductVariants::class)($product, [
            new ProductVariantData(
                label: '25*35', widthCm: 25, heightCm: 35, isActive: true, sortOrder: 0,
                priceTiers: [new PriceTierData(minQuantity: '1', unitPrice: '0.500')], id: $keep->id,
            ),
        ]);

        // Assert
        $this->assertNull(ProductVariant::query()->find($drop->id));
        $this->assertSame(0, ProductPriceTier::query()->where('product_variant_id', $drop->id)->count());
        $this->assertSame(2, ProductPriceTier::withTrashed()->where('product_variant_id', $drop->id)->count());
    }

    public function test_dropping_a_shop_from_a_customer_soft_deletes_it_and_records_it(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $shop = CustomerShop::factory()->create(['customer_id' => $customer->id]);

        // Act
        app(SyncCustomerShops::class)($customer, [
            new CustomerShopData(name: 'محل جديد', cityId: $shop->city_id),
        ]);

        // Assert
        $this->assertNull(CustomerShop::query()->find($shop->id));
        $this->assertNotNull(CustomerShop::withTrashed()->find($shop->id));
        $this->assertSame(1, ActivityLog::query()
            ->where('subject_type', 'customer_shop')
            ->where('subject_id', $shop->id)
            ->where('event', 'deleted')
            ->count());
    }

    // ─────────────────────────── the name is released ───────────────────────────

    public function test_a_deleted_city_releases_its_name_for_a_new_one(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'الخمس']);
        $city->delete();

        // Act — this is a raw insert on purpose: the partial unique index is what has to allow
        // it, not the validation layer sitting above it.
        $replacement = City::factory()->create(['name' => 'الخمس']);

        // Assert
        $this->assertNotSame($city->id, $replacement->id);
        $this->assertSame(2, City::withTrashed()->where('name', 'الخمس')->count());
    }

    public function test_two_live_cities_still_cannot_share_a_name(): void
    {
        // Arrange
        City::factory()->create(['name' => 'سرت']);

        // Assert (set before the Act, as PHPUnit requires)
        $this->expectException(QueryException::class);

        // Act — the partial index narrows what uniqueness means; it does not weaken it.
        City::factory()->create(['name' => 'سرت']);
    }

    public function test_validation_agrees_with_the_index_about_a_deleted_name(): void
    {
        // Arrange
        $this->seedPermissions();
        $user = User::factory()->create();
        $user->givePermissionTo(['cities.manage', 'cities.view']);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        City::factory()->create(['name' => 'زليتن'])->delete();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/cities', [
            'name' => 'زليتن',
            'delivery_price' => '25.00',
        ]);

        // Assert — without `withoutTrashed()` on the rule this is a 422 naming a record the
        // caller can neither see nor recover.
        $response->assertCreated()->assertJsonPath('data.name', 'زليتن');
    }

    public function test_a_deleted_customer_releases_its_phone_number(): void
    {
        // Arrange
        $customer = Customer::factory()->create(['phone' => '0915555555']);
        $customer->delete();

        // Act
        $replacement = Customer::factory()->create(['phone' => '0915555555']);

        // Assert
        $this->assertNotSame($customer->id, $replacement->id);
    }

    public function test_a_deleted_image_stops_blocking_the_primary_slot(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $first = ProductImage::factory()
            ->create(['product_id' => $product->id, 'is_primary' => true]);
        $first->delete();

        // Act — the partial index gained "and not deleted"; without it the product could never
        // have a primary photo again.
        $second = ProductImage::factory()
            ->create(['product_id' => $product->id, 'is_primary' => true]);

        // Assert
        $this->assertTrue($second->is_primary);
        $this->assertSame(1, $product->images()->where('is_primary', true)->count());
    }

    // ─────────────────────────── lists ───────────────────────────

    public function test_a_deleted_record_is_gone_from_its_listing_endpoint(): void
    {
        // Arrange
        $this->seedPermissions();
        $user = User::factory()->create();
        $user->givePermissionTo('cities.view');
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        City::factory()->create(['name' => 'باقية']);
        City::factory()->create(['name' => 'محذوفة'])->delete();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/cities');

        // Assert
        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertContains('باقية', $names);
        $this->assertNotContains('محذوفة', $names);
    }

    public function test_fetching_a_deleted_record_is_a_404(): void
    {
        // Arrange
        $this->seedPermissions();
        $user = User::factory()->create();
        $user->givePermissionTo('cities.view');
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        $city = City::factory()->create();
        $city->delete();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}");

        // Assert
        $response->assertNotFound()->assertJsonPath('message', 'العنصر المطلوب غير موجود');
    }

    private function seedPermissions(): void
    {
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }
}
