<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Customer\Models\BusinessField;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerShop;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Every test follows Arrange - Act - Assert: state is prepared first, exactly one call is
 * made, and only then is anything asserted.
 */
class CustomerTest extends TestCase
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
     * Empties the table and rewinds the id sequence, so a test can assert on the very first
     * code (A1) rather than on whatever number the sequence happens to be at.
     */
    private function resetCustomerSequence(): void
    {
        DB::statement('TRUNCATE customers RESTART IDENTITY CASCADE');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'مخبز النخيل',
            'phone' => '0912345678',
        ], $overrides);
    }

    /**
     * A place on the delivery map, which every shop payload needs: a shop is recorded by where
     * it is, and «where» is a row in that map rather than a pair of numbers.
     *
     * Created here rather than in a factory state so each test can name the city it is talking
     * about — «طرابلس» reads better in an assertion than «مدينة 7».
     */
    private function city(string $name = 'طرابلس'): City
    {
        return City::factory()->create(['name' => $name]);
    }

    // ─────────────────────────── customer code ───────────────────────────

    public function test_the_first_customer_gets_the_code_a1(): void
    {
        // Arrange
        $this->resetCustomerSequence();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.code', 'A1')
            ->assertJsonPath('data.id', 1);
    }

    public function test_codes_increment_a1_a2_a3_in_order(): void
    {
        // Arrange
        $this->resetCustomerSequence();
        $headers = $this->auth();

        // Act — each needs its own phone number, since one number belongs to one customer.
        $codes = [];
        foreach (['أول', 'ثاني', 'ثالث'] as $index => $name) {
            $codes[] = $this->withHeaders($headers)
                ->postJson('/api/v1/customers', $this->payload([
                    'name' => $name,
                    'phone' => '09120000'.(10 + $index),
                ]))
                ->assertCreated()
                ->json('data.code');
        }

        // Assert
        $this->assertSame(['A1', 'A2', 'A3'], $codes);
    }

    public function test_the_code_always_matches_the_id(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $this->payload());

        // Assert
        $response->assertCreated();
        $this->assertSame('A'.$response->json('data.id'), $response->json('data.code'));
    }

    public function test_a_client_cannot_choose_its_own_code_or_id(): void
    {
        // Arrange
        $headers = $this->auth();
        $payload = $this->payload(['code' => 'C999', 'id' => 999]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert
        $response->assertCreated();
        $this->assertNotSame('C999', $response->json('data.code'));
        $this->assertNotSame(999, $response->json('data.id'));
        $this->assertDatabaseMissing('customers', ['code' => 'C999']);
    }

    public function test_the_code_does_not_change_when_the_customer_is_updated(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $originalCode = $customer->code;
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/customers/{$customer->id}", $this->payload(['name' => 'اسم جديد']));

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.code', $originalCode)
            ->assertJsonPath('data.name', 'اسم جديد');
    }

    // ─────────────────────────── create ───────────────────────────

    public function test_create_stores_the_customer_and_defaults_to_active(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJson([
                'status' => true,
                'message' => 'تم إضافة العميل بنجاح',
                'data' => [
                    'name' => 'مخبز النخيل',
                    'phone' => '0912345678',
                    'is_active' => true,
                    'shops' => [],
                ],
            ])
            ->assertJsonStructure([
                'status',
                'message',
                'data' => ['id', 'code', 'name', 'phone', 'is_active', 'shops', 'created_at', 'updated_at'],
            ]);

        $this->assertDatabaseHas('customers', ['name' => 'مخبز النخيل', 'is_active' => true]);
    }

    public function test_create_accepts_shops_inline(): void
    {
        // Arrange
        $tripoli = $this->city('طرابلس');
        $benghazi = $this->city('بنغازي');
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [
                ['name' => 'فرع طرابلس', 'city_id' => $tripoli->id, 'page_url' => 'https://facebook.com/branch1'],
                ['name' => 'فرع بنغازي', 'city_id' => $benghazi->id],
            ],
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert
        $response->assertCreated()
            ->assertJsonCount(2, 'data.shops')
            ->assertJsonPath('data.shops.0.name', 'فرع طرابلس')
            ->assertJsonPath('data.shops.0.city.name', 'طرابلس')
            ->assertJsonPath('data.shops.0.page_url', 'https://facebook.com/branch1')
            // A shop without a page link stores null rather than an empty string.
            ->assertJsonPath('data.shops.1.page_url', null);

        $this->assertDatabaseCount('customer_shops', 2);
        $this->assertDatabaseHas('customer_shops', [
            'customer_id' => $response->json('data.id'),
            'name' => 'فرع بنغازي',
            'page_url' => null,
        ]);
    }

    public function test_coordinates_are_returned_as_numbers_not_strings(): void
    {
        // Arrange — a decimal column reads back as a string unless it is cast, and a map SDK
        // needs numbers. The app no longer sends a pin, but the endpoint still takes one, so
        // this stays: it is the contract the pin comes back through the day the map returns.
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [['name' => 'فرع', 'city_id' => $this->city()->id, 'latitude' => 32.8872, 'longitude' => 13.1913]],
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert
        $response->assertCreated();
        $shop = $response->json('data.shops.0');
        $this->assertIsFloat($shop['latitude']);
        $this->assertIsFloat($shop['longitude']);
    }

    public function test_coordinates_keep_their_precision(): void
    {
        // Arrange — decimal(10,7) must not round a 7-place coordinate away.
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [['name' => 'فرع', 'city_id' => $this->city()->id, 'latitude' => 32.8872123, 'longitude' => -13.1913456]],
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.shops.0.latitude', 32.8872123)
            ->assertJsonPath('data.shops.0.longitude', -13.1913456);
    }

    // ─────────────────── مدينة المحل ومنطقته ───────────────────

    public function test_a_shop_records_the_city_and_the_region_it_is_in(): void
    {
        // Arrange — the same delivery map an order is addressed from, so a shop and the order
        // going to it speak about place in one vocabulary.
        $tripoli = $this->city('طرابلس');
        $soukAlJumaa = Region::factory()->create(['city_id' => $tripoli->id, 'name' => 'سوق الجمعة']);
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [[
                'name' => 'محل الأناقة',
                'city_id' => $tripoli->id,
                'region_id' => $soukAlJumaa->id,
            ]],
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert — the ids for a form to preselect, and the names so a screen never fetches the
        // map to translate two numbers. The same bargain `business_field` already makes.
        $response->assertCreated()
            ->assertJsonPath('data.shops.0.city_id', $tripoli->id)
            ->assertJsonPath('data.shops.0.city.name', 'طرابلس')
            ->assertJsonPath('data.shops.0.region_id', $soukAlJumaa->id)
            ->assertJsonPath('data.shops.0.region.name', 'سوق الجمعة');

        $this->assertDatabaseHas('customer_shops', [
            'name' => 'محل الأناقة',
            'city_id' => $tripoli->id,
            'region_id' => $soukAlJumaa->id,
        ]);
    }

    public function test_a_shop_may_have_a_city_without_a_region(): void
    {
        // Arrange — most cities on the map have no neighbourhoods at all, and the clerk taking
        // a customer over the phone often does not know the district yet. Neither may block a
        // customer from being saved: `is_region_required` is a delivery rule, and it is the
        // order that has to answer it.
        $city = City::factory()->requiringRegion()->create(['name' => 'مصراتة']);
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [['name' => 'فرع مصراتة', 'city_id' => $city->id]],
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.shops.0.city_id', $city->id)
            ->assertJsonPath('data.shops.0.region_id', null)
            ->assertJsonPath('data.shops.0.region', null);
    }

    public function test_a_shop_is_refused_without_a_city(): void
    {
        // Arrange — the one thing that replaced the pin, so it is the one thing required.
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [['name' => 'فرع بلا عنوان']],
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('shops.0.city_id');
    }

    public function test_a_region_from_another_city_is_refused(): void
    {
        // Arrange — «طرابلس / سوق الخميس الزاوية» is a row with no meaning, and `exists` alone
        // would happily store it: the region is real, it is simply not in that city.
        $tripoli = $this->city('طرابلس');
        $zawiya = $this->city('الزاوية');
        $foreignRegion = Region::factory()->create(['city_id' => $zawiya->id, 'name' => 'سوق الخميس']);
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [[
                'name' => 'محل الأناقة',
                'city_id' => $tripoli->id,
                'region_id' => $foreignRegion->id,
            ]],
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('shops.0.region_id');
    }

    public function test_changing_the_city_of_a_shop_clears_a_region_left_behind(): void
    {
        // Arrange — the shop moves to a city where its old neighbourhood does not exist.
        $tripoli = $this->city('طرابلس');
        $region = Region::factory()->create(['city_id' => $tripoli->id, 'name' => 'سوق الجمعة']);
        $zawiya = $this->city('الزاوية');
        $customer = Customer::factory()->create();
        $shop = CustomerShop::factory()->create([
            'customer_id' => $customer->id,
            'city_id' => $tripoli->id,
            'region_id' => $region->id,
        ]);
        $headers = $this->auth();

        // Act — the whole shop is sent back, this time with a different city and no region.
        $response = $this->withHeaders($headers)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'shops' => [['id' => $shop->id, 'name' => $shop->name, 'city_id' => $zawiya->id]],
        ]);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.shops.0.city_id', $zawiya->id)
            ->assertJsonPath('data.shops.0.region_id', null);
        $this->assertNull($shop->fresh()->region_id);
    }

    public function test_saving_a_shop_without_coordinates_keeps_the_pin_it_already_had(): void
    {
        // Arrange — the pin was dropped from the form, not from the database. An edit made
        // through a screen that no longer asks for coordinates must not erase the ones a shop
        // was recorded with, or the columns kept for the map's return come back empty.
        $customer = Customer::factory()->create();
        $shop = CustomerShop::factory()->create([
            'customer_id' => $customer->id,
            'latitude' => 32.8872123,
            'longitude' => 13.1913456,
        ]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'shops' => [[
                'id' => $shop->id,
                'name' => 'الاسم بعد التعديل',
                'city_id' => $shop->city_id,
            ]],
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.shops.0.name', 'الاسم بعد التعديل');
        $fresh = $shop->fresh();
        $this->assertSame(32.8872123, $fresh->latitude);
        $this->assertSame(13.1913456, $fresh->longitude);
    }

    public function test_create_can_set_the_customer_inactive(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/customers', $this->payload(['is_active' => false]));

        // Assert
        $response->assertCreated()->assertJsonPath('data.is_active', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidCustomerCases')]
    public function test_create_rejects_invalid_input(array $overrides, string $invalidField): void
    {
        // Arrange
        $headers = $this->auth();
        $payload = $this->payload($overrides);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert
        $response->assertStatus(422)
            ->assertJson(['status' => false, 'data' => null])
            ->assertJsonValidationErrors($invalidField);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidCustomerCases(): array
    {
        return [
            'name missing' => [['name' => ''], 'name'],
            'name too short' => [['name' => 'م'], 'name'],
            'phone missing' => [['phone' => ''], 'phone'],
            'phone too short' => [['phone' => '12345'], 'phone'],
            'phone with letters' => [['phone' => '09abcdefgh'], 'phone'],
            'phone with symbols' => [['phone' => '091-234-5678'], 'phone'],
            'is_active not boolean' => [['is_active' => 'maybe'], 'is_active'],
            'shops not a list' => [['shops' => 'nope'], 'shops'],
            'shop without a name' => [['shops' => [['latitude' => 32.1, 'longitude' => 13.1]]], 'shops.0.name'],
            // A city that does not exist — a stale id from a client's cached map. The «no city
            // at all» case has its own test, where a real city can be created to contrast with.
            'shop with a city that does not exist' => [['shops' => [['name' => 'x', 'city_id' => 999999]]], 'shops.0.city_id'],
            'shop with a region that does not exist' => [['shops' => [['name' => 'x', 'region_id' => 999999]]], 'shops.0.region_id'],
            // Coordinates are optional now that the form no longer asks for them — but a value
            // that *is* sent still has to be a real one, so the bounds stay.
            'latitude above 90' => [['shops' => [['name' => 'x', 'latitude' => 90.1, 'longitude' => 13.1]]], 'shops.0.latitude'],
            'latitude below -90' => [['shops' => [['name' => 'x', 'latitude' => -90.1, 'longitude' => 13.1]]], 'shops.0.latitude'],
            'longitude above 180' => [['shops' => [['name' => 'x', 'latitude' => 32.1, 'longitude' => 180.1]]], 'shops.0.longitude'],
            'longitude below -180' => [['shops' => [['name' => 'x', 'latitude' => 32.1, 'longitude' => -180.1]]], 'shops.0.longitude'],
            'latitude not numeric' => [['shops' => [['name' => 'x', 'latitude' => 'شمال', 'longitude' => 13.1]]], 'shops.0.latitude'],
            'longitude not numeric' => [['shops' => [['name' => 'x', 'latitude' => 32.1, 'longitude' => 'شرق']]], 'shops.0.longitude'],
            'shop with a bad url' => [['shops' => [['name' => 'x', 'latitude' => 32.1, 'longitude' => 13.1, 'page_url' => 'not-a-url']]], 'shops.0.page_url'],
        ];
    }

    // ─────────────────── one phone belongs to one customer ───────────────────

    public function test_create_rejects_a_phone_already_used_by_another_customer(): void
    {
        // Arrange
        Customer::factory()->create(['phone' => '0915550009']);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/customers', $this->payload(['phone' => '0915550009']));

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors('phone')
            ->assertJsonPath('errors.phone.0', 'رقم الهاتف مستخدم مسبقاً لعميل آخر');

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_update_allows_a_customer_to_keep_its_own_phone(): void
    {
        // Arrange
        $customer = Customer::factory()->create(['phone' => '0915551111']);
        $headers = $this->auth();

        // Act — saving the form untouched must not collide with itself.
        $response = $this->withHeaders($headers)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'اسم محدث',
            'phone' => '0915551111',
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.phone', '0915551111');
    }

    public function test_update_rejects_a_phone_taken_by_a_different_customer(): void
    {
        // Arrange
        $customer = Customer::factory()->create(['phone' => '0915552222']);
        Customer::factory()->create(['phone' => '0915553333']);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'اسم',
            'phone' => '0915553333',
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'phone' => '0915552222']);
    }

    public function test_the_database_itself_refuses_a_duplicate_phone(): void
    {
        // Arrange — validation can be bypassed by a seeder or a race; the index cannot.
        Customer::factory()->create(['phone' => '0915554444']);

        // Assert
        $this->expectException(QueryException::class);

        // Act
        Customer::factory()->create(['phone' => '0915554444']);
    }

    public function test_create_requires_authentication(): void
    {
        // Act
        $response = $this->postJson('/api/v1/customers', $this->payload());

        // Assert
        $response->assertStatus(401)->assertJson(['status' => false, 'data' => null]);
    }

    // ─────────────────────────── list ───────────────────────────

    public function test_index_returns_a_paginated_envelope_newest_first(): void
    {
        // Arrange
        Customer::factory()->count(3)->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/customers');

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [['id', 'code', 'name', 'phone', 'is_active', 'shops']],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ]);

        $ids = array_column($response->json('data'), 'id');
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids, 'Customers should be listed newest first.');
    }

    public function test_index_handles_an_empty_result_set(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/customers');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_index_paginates(): void
    {
        // Arrange
        Customer::factory()->count(5)->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/customers?per_page=2');

        // Assert
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_index_caps_an_absurd_page_size(): void
    {
        // Arrange
        Customer::factory()->count(3)->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/customers?per_page=100000');

        // Assert
        $response->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_index_searches_by_name_code_and_phone(): void
    {
        // Arrange
        $target = Customer::factory()->create(['name' => 'مطبعة الأمل', 'phone' => '0915550001']);
        Customer::factory()->create(['name' => 'شركة أخرى', 'phone' => '0918880002']);
        $headers = $this->auth();

        foreach (['مطبعة', $target->code, '5550001'] as $term) {
            // Act
            $response = $this->withHeaders($headers)
                ->getJson('/api/v1/customers?search='.urlencode((string) $term));

            // Assert
            $response->assertOk();
            $this->assertSame(
                [$target->id],
                array_column($response->json('data'), 'id'),
                "Search term [{$term}] should match only the target customer.",
            );
        }
    }

    public function test_index_search_is_case_insensitive(): void
    {
        // Arrange
        $target = Customer::factory()->create(['name' => 'Alpha Printing']);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/customers?search=alpha');

        // Assert
        $response->assertOk();
        $this->assertSame([$target->id], array_column($response->json('data'), 'id'));
    }

    public function test_index_filters_by_activity(): void
    {
        // Arrange
        $active = Customer::factory()->create();
        $inactive = Customer::factory()->inactive()->create();
        $headers = $this->auth();

        // Act
        $activeOnly = $this->withHeaders($headers)->getJson('/api/v1/customers?is_active=1');
        $inactiveOnly = $this->withHeaders($headers)->getJson('/api/v1/customers?is_active=0');

        // Assert
        $this->assertSame([$active->id], array_column($activeOnly->json('data'), 'id'));
        $this->assertSame([$inactive->id], array_column($inactiveOnly->json('data'), 'id'));
    }

    public function test_index_requires_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/customers');

        // Assert
        $response->assertStatus(401);
    }

    // ─────────────────────────── مجال العمل ───────────────────────────

    public function test_a_shop_records_the_trade_it_is_in(): void
    {
        // Arrange — the whole reason the field exists: knowing who we sell to, from records
        // rather than from memory.
        $field = BusinessField::factory()->named('بيع ملابس')->create();
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [
                [
                    'name' => 'محل الأناقة',
                    'city_id' => $this->city()->id,
                    'business_field_id' => $field->id,
                ],
            ],
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert — the id for a form to preselect, and the field itself so a screen never has
        // to fetch the list to translate one number.
        $response->assertCreated()
            ->assertJsonPath('data.shops.0.business_field_id', $field->id)
            ->assertJsonPath('data.shops.0.business_field.name', 'بيع ملابس');
    }

    public function test_a_shop_may_be_recorded_without_a_trade(): void
    {
        // Arrange — every shop on record predates this field, and a shop entered in a hurry
        // still has to save.
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [['name' => 'فرع طرابلس', 'city_id' => $this->city()->id]],
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert
        $response->assertCreated()->assertJsonPath('data.shops.0.business_field_id', null);
    }

    public function test_the_trade_of_an_existing_shop_can_be_changed_and_cleared(): void
    {
        // Arrange
        $field = BusinessField::factory()->named('مطاعم ومقاهي')->create();
        $customer = Customer::factory()->create();
        $shop = CustomerShop::factory()->create([
            'customer_id' => $customer->id,
            'business_field_id' => $field->id,
        ]);
        $headers = $this->auth();

        // Act — the shop is sent back with its id and without a trade, which is how this API
        // says «امسحه»: both endpoints send the shop's whole representation.
        $response = $this->withHeaders($headers)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'shops' => [[
                'id' => $shop->id,
                'name' => $shop->name,
                'city_id' => $shop->city_id,
            ]],
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.shops.0.business_field_id', null);
        $this->assertNull($shop->fresh()->business_field_id);
    }

    public function test_a_trade_that_does_not_exist_is_refused(): void
    {
        // Arrange — a stale id from a client's cached list must not reach the database.
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [[
                'name' => 'فرع طرابلس',
                'city_id' => $this->city()->id,
                'business_field_id' => 999999,
            ]],
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', $payload);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('shops.0.business_field_id');
    }

    // ─────────────────────────── show ───────────────────────────

    public function test_show_returns_the_customer_with_its_shops(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        CustomerShop::factory()->count(2)->create(['customer_id' => $customer->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/customers/{$customer->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonPath('data.code', $customer->code)
            ->assertJsonCount(2, 'data.shops');
    }

    public function test_show_returns_404_for_an_unknown_customer(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/customers/999999');

        // Assert
        $response->assertNotFound()
            ->assertJson([
                'status' => false,
                'message' => 'العنصر المطلوب غير موجود',
                'data' => null,
            ]);
    }

    public function test_show_requires_authentication(): void
    {
        // Arrange
        $customer = Customer::factory()->create();

        // Act
        $response = $this->getJson("/api/v1/customers/{$customer->id}");

        // Assert
        $response->assertStatus(401);
    }

    // ─────────────────────────── update ───────────────────────────

    public function test_update_changes_the_basic_fields(): void
    {
        // Arrange
        $customer = Customer::factory()->create(['name' => 'قديم', 'phone' => '0911111111']);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/customers/{$customer->id}", [
            'name' => 'جديد',
            'phone' => '0922222222',
        ]);

        // Assert
        $response->assertOk()
            ->assertJson([
                'status' => true,
                'message' => 'تم تحديث بيانات العميل بنجاح',
                'data' => ['name' => 'جديد', 'phone' => '0922222222'],
            ]);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'جديد']);
    }

    public function test_update_without_is_active_does_not_reactivate_a_deactivated_customer(): void
    {
        // Arrange
        $customer = Customer::factory()->inactive()->create();
        $headers = $this->auth();

        // Act — payload deliberately omits is_active.
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/customers/{$customer->id}", $this->payload());

        // Assert
        $response->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'is_active' => false]);
    }

    public function test_update_leaves_shops_alone_when_the_key_is_absent(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        CustomerShop::factory()->count(2)->create(['customer_id' => $customer->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/customers/{$customer->id}", $this->payload());

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data.shops');
        $this->assertDatabaseCount('customer_shops', 2);
    }

    public function test_update_with_an_empty_shops_array_removes_every_shop(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        CustomerShop::factory()->count(2)->create(['customer_id' => $customer->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/customers/{$customer->id}", $this->payload(['shops' => []]));

        // Assert — shops are soft deleted, so the rows survive; what must be gone is the
        // customer's *live* set. Counting through the model applies the soft-delete scope,
        // which assertDatabaseCount does not.
        $response->assertOk()->assertJsonCount(0, 'data.shops');
        $this->assertSame(0, CustomerShop::query()->count());
        $this->assertSame(2, CustomerShop::withTrashed()->count());
    }

    public function test_update_syncs_shops_updating_adding_and_removing_in_one_call(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $kept = CustomerShop::factory()->create(['customer_id' => $customer->id, 'name' => 'الأصلي']);
        $removed = CustomerShop::factory()->create(['customer_id' => $customer->id]);
        $city = $this->city();
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [
                ['id' => $kept->id, 'name' => 'الأصلي المعدل', 'city_id' => $city->id],
                ['name' => 'محل مضاف', 'city_id' => $city->id],
            ],
        ]);

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/customers/{$customer->id}", $payload);

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data.shops');

        // The kept shop was edited in place, so its id survived.
        $this->assertDatabaseHas('customer_shops', [
            'id' => $kept->id,
            'name' => 'الأصلي المعدل',
            'city_id' => $city->id,
        ]);
        // Soft deleted, not erased — the row stays for the audit trail, but it is out of the set.
        $this->assertSoftDeleted('customer_shops', ['id' => $removed->id]);
        $this->assertDatabaseHas('customer_shops', ['customer_id' => $customer->id, 'name' => 'محل مضاف']);
        $this->assertSame(2, CustomerShop::query()->count());
    }

    public function test_update_rejects_a_shop_id_belonging_to_another_customer(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $otherShop = CustomerShop::factory()->create();
        $headers = $this->auth();
        $payload = $this->payload([
            'shops' => [['id' => $otherShop->id, 'name' => 'اختراق', 'city_id' => $otherShop->city_id]],
        ]);

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/customers/{$customer->id}", $payload);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('shops.0.id');
        $this->assertDatabaseHas('customer_shops', ['id' => $otherShop->id, 'name' => $otherShop->name]);
    }

    public function test_update_validates_its_input(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/customers/{$customer->id}", ['name' => '', 'phone' => 'abc']);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'phone']);
    }

    public function test_update_returns_404_for_an_unknown_customer(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->putJson('/api/v1/customers/999999', $this->payload());

        // Assert
        $response->assertNotFound();
    }

    public function test_update_requires_authentication(): void
    {
        // Arrange
        $customer = Customer::factory()->create();

        // Act
        $response = $this->putJson("/api/v1/customers/{$customer->id}", $this->payload());

        // Assert
        $response->assertStatus(401);
    }

    // ─────────────────────────── activation ───────────────────────────

    public function test_activation_can_deactivate_a_customer(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/customers/{$customer->id}/activation", ['is_active' => false]);

        // Assert
        $response->assertOk()
            ->assertJson(['message' => 'تم إلغاء تنشيط العميل'])
            ->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'is_active' => false]);
    }

    public function test_activation_can_reactivate_a_customer(): void
    {
        // Arrange
        $customer = Customer::factory()->inactive()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/customers/{$customer->id}/activation", ['is_active' => true]);

        // Assert
        $response->assertOk()
            ->assertJson(['message' => 'تم تنشيط العميل'])
            ->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'is_active' => true]);
    }

    public function test_activation_requires_the_flag(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/customers/{$customer->id}/activation", []);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('is_active');
    }

    public function test_activation_requires_authentication(): void
    {
        // Arrange
        $customer = Customer::factory()->create();

        // Act
        $response = $this->patchJson("/api/v1/customers/{$customer->id}/activation", ['is_active' => false]);

        // Assert
        $response->assertStatus(401);
    }

    // ─────────────────────────── deletion is not offered ───────────────────────────

    public function test_customers_cannot_be_deleted(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $headers = $this->auth();

        // Act — deactivation replaces deletion, so the route does not exist at all.
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/customers/{$customer->id}");

        // Assert
        $response->assertStatus(405);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    // ─────────────────────────── relation integrity ───────────────────────────

    public function test_soft_deleting_a_customer_leaves_its_shops_in_place(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        CustomerShop::factory()->count(2)->create(['customer_id' => $customer->id]);

        // Act
        $customer->delete();

        // Assert — a soft delete does not touch the child rows, because the row itself is still
        // there. Nothing in the API reaches them, since every route goes through the customer.
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->assertSame(2, CustomerShop::query()->count());
    }

    public function test_force_deleting_a_customer_cascades_to_its_shops(): void
    {
        // Arrange — the database-level guarantee, which only a real delete exercises.
        $customer = Customer::factory()->create();
        CustomerShop::factory()->count(2)->create(['customer_id' => $customer->id]);

        // Act
        $customer->forceDelete();

        // Assert
        $this->assertSame(0, CustomerShop::withTrashed()->count());
    }

    // ── how much business each customer does ─────────────────────────────────
    // The number on the card in the list, and on their own screen. See
    // CUSTOMER-ORDERS-SECTION.md §٣.

    public function test_the_list_says_how_many_orders_each_customer_has(): void
    {
        // Arrange
        $busy = Customer::factory()->create();
        $quiet = Customer::factory()->create();
        Order::factory()->count(3)->forCustomer($busy)->create();

        // Act
        $response = $this->withHeaders($this->auth())->getJson('/api/v1/customers');

        // Assert — a zero for the customer who has never ordered, not a missing key: the two
        // read differently on a card, and only one of them is true here.
        $counts = collect($response->json('data'))->pluck('orders_count', 'id');

        $response->assertOk();
        $this->assertSame(3, $counts[$busy->id]);
        $this->assertSame(0, $counts[$quiet->id]);
    }

    public function test_one_customer_carries_the_same_number_as_their_row(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        Order::factory()->count(2)->forCustomer($customer)->create();
        Order::factory()->count(5)->create();

        // Act
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/customers/{$customer->id}");

        // Assert — theirs, not the shop's.
        $response->assertOk()->assertJsonPath('data.orders_count', 2);
    }

    public function test_a_soft_deleted_order_is_not_counted(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $orders = Order::factory()->count(3)->forCustomer($customer)->create();
        $orders->first()->delete();

        // Act
        $response = $this->withHeaders($this->auth())->getJson("/api/v1/customers/{$customer->id}");

        // Assert — the same set every other count in this system means by "orders".
        $response->assertOk()->assertJsonPath('data.orders_count', 2);
    }

    public function test_a_reader_who_may_not_see_orders_is_not_told_how_many_there_are(): void
    {
        // Arrange — customers, and nothing about orders.
        foreach ([PermissionName::ViewCustomers, PermissionName::ViewOrders] as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewCustomers->value);

        $customer = Customer::factory()->create();
        Order::factory()->count(3)->forCustomer($customer)->create();

        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/customers/{$customer->id}");

        // Assert — the key is absent rather than zero. A nought would be a different lie from
        // «you may not know», and the app draws a card off this.
        $response->assertOk();
        $this->assertArrayNotHasKey('orders_count', $response->json('data'));
    }

    public function test_counting_a_page_of_customers_costs_one_query(): void
    {
        // Arrange
        $customers = Customer::factory()->count(5)->create();
        foreach ($customers as $customer) {
            Order::factory()->count(2)->forCustomer($customer)->create();
        }

        $headers = $this->auth();
        DB::enableQueryLog();

        // Act
        $this->withHeaders($headers)->getJson('/api/v1/customers')->assertOk();

        // Assert — one `where customer_id in (…)`, not one per row. This is the whole reason the
        // count is a query over the orders rather than a relation walked per customer.
        $counting = collect(DB::getRawQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['raw_query'], 'from "orders"'))
            ->values();

        DB::disableQueryLog();
        $this->assertCount(1, $counting);
    }

    // ── who has stopped ordering ─────────────────────────────────────────────
    // The two questions the list is asked when somebody sits down to make calls: who has never
    // ordered, and who has not ordered for the longest. See CUSTOMERS-ACTIVITY-FILTER.md.

    public function test_the_list_can_be_narrowed_to_the_customers_who_have_never_ordered(): void
    {
        // Arrange
        $ordered = Customer::factory()->create();
        $never = Customer::factory()->create();
        Order::factory()->forCustomer($ordered)->create();

        // Act
        $response = $this->withHeaders($this->auth())->getJson('/api/v1/customers?has_orders=0');

        // Assert
        $ids = collect($response->json('data'))->pluck('id')->all();

        $response->assertOk();
        $this->assertSame([$never->id], $ids);
    }

    public function test_the_list_can_be_narrowed_to_the_customers_who_have_ordered(): void
    {
        // Arrange — the complement of the filter above, and its own question: «من نبيع لهم
        // فعلاً؟» is asked as often as «من لم يشترِ قطّ؟».
        $ordered = Customer::factory()->create();
        Customer::factory()->create();
        Order::factory()->forCustomer($ordered)->create();

        // Act
        $response = $this->withHeaders($this->auth())->getJson('/api/v1/customers?has_orders=1');

        // Assert
        $ids = collect($response->json('data'))->pluck('id')->all();

        $response->assertOk();
        $this->assertSame([$ordered->id], $ids);
    }

    public function test_a_cancelled_order_still_makes_a_customer_one_who_has_ordered(): void
    {
        // Arrange — the same set `orders_count` counts: a card reading «١ طلبية» must never
        // appear inside a list titled «بدون طلبات».
        $customer = Customer::factory()->create();
        Order::factory()->forCustomer($customer)->status(OrderStatus::Cancelled)->create();

        // Act
        $response = $this->withHeaders($this->auth())->getJson('/api/v1/customers?has_orders=0');

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_soft_deleted_order_leaves_its_customer_among_those_who_never_ordered(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        Order::factory()->forCustomer($customer)->create()->delete();

        // Act
        $response = $this->withHeaders($this->auth())->getJson('/api/v1/customers?has_orders=0');

        // Assert — a deleted order is one that never happened, here as everywhere else.
        $response->assertOk()->assertJsonPath('data.0.id', $customer->id);
    }

    public function test_never_ordered_narrows_the_search_rather_than_replacing_it(): void
    {
        // Arrange
        $wanted = Customer::factory()->create(['name' => 'مطبعة الأمل']);
        Customer::factory()->create(['name' => 'مخبز الأمل']);
        Order::factory()->forCustomer(Customer::factory()->create(['name' => 'مطبعة النور']))->create();

        // Act
        $response = $this->withHeaders($this->auth())->getJson('/api/v1/customers?'.http_build_query(['has_orders' => '0', 'search' => 'مطبعة']));

        // Assert — both conditions, not the last one to be read.
        $ids = collect($response->json('data'))->pluck('id')->all();

        $response->assertOk();
        $this->assertSame([$wanted->id], $ids);
    }

    public function test_the_list_can_be_sorted_by_the_longest_since_the_last_order(): void
    {
        // Arrange
        $stale = Customer::factory()->create();
        $recent = Customer::factory()->create();
        Order::factory()->forCustomer($stale)->create(['placed_at' => now()->subMonths(8)]);
        Order::factory()->forCustomer($recent)->create(['placed_at' => now()->subDays(3)]);
        // Their *latest* order is what places them, not their first.
        Order::factory()->forCustomer($stale)->create(['placed_at' => now()->subMonths(5)]);

        // Act
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/customers?sort=least_recent_order');

        // Assert
        $ids = collect($response->json('data'))->pluck('id')->all();

        $response->assertOk();
        $this->assertSame([$stale->id, $recent->id], $ids);
    }

    public function test_a_customer_who_has_never_ordered_sorts_after_everyone_who_has(): void
    {
        // Arrange
        $never = Customer::factory()->create();
        $stale = Customer::factory()->create();
        Order::factory()->forCustomer($stale)->create(['placed_at' => now()->subYear()]);

        // Act
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/customers?sort=least_recent_order');

        // Assert — last, not first: «لم يطلب أبداً» is its own filter, and this list is for the
        // customers who used to order and stopped.
        $ids = collect($response->json('data'))->pluck('id')->all();

        $response->assertOk();
        $this->assertSame([$stale->id, $never->id], $ids);
    }

    public function test_the_oldest_first_list_says_when_each_customer_last_ordered(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $placedAt = now()->subMonths(3)->startOfSecond();
        Order::factory()->forCustomer($customer)->create(['placed_at' => $placedAt]);

        // Act
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/customers?sort=least_recent_order');

        // Assert — the card shows the number the list was sorted by, so it is sent with it.
        $response->assertOk()
            ->assertJsonPath('data.0.last_order_at', $placedAt->toIso8601String());
    }

    public function test_a_customer_who_never_ordered_carries_a_null_last_order_date(): void
    {
        // Arrange
        $customer = Customer::factory()->create();

        // Act
        $response = $this->withHeaders($this->auth())
            ->getJson('/api/v1/customers?sort=least_recent_order');

        // Assert — present and null, which is «لم يطلب أبداً»; a missing key would be «لم نسأل».
        $response->assertOk()
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.last_order_at', null);
    }

    public function test_a_row_carries_no_last_order_date_unless_the_list_was_sorted_by_one(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        Order::factory()->forCustomer($customer)->create();

        // Act
        $response = $this->withHeaders($this->auth())->getJson('/api/v1/customers');

        // Assert — the ordinary list pays for no subquery it does not show.
        $response->assertOk();
        $this->assertArrayNotHasKey('last_order_at', $response->json('data.0'));
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('orderDerivedQueries')]
    public function test_a_reader_who_may_not_see_orders_may_not_ask_about_them(array $query): void
    {
        // Arrange — customers, and nothing about orders.
        foreach ([PermissionName::ViewCustomers, PermissionName::ViewOrders] as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewCustomers->value);

        Customer::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/customers?'.http_build_query($query));

        // Assert — refused rather than answered without the filter: a list that quietly ignored
        // it would be the wrong answer to the question that was asked. The count is merely
        // omitted for this reader, because there the question was never theirs.
        $response->assertForbidden();
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function orderDerivedQueries(): array
    {
        return [
            'the never-ordered filter' => [['has_orders' => '0']],
            'the least-recent-order sort' => [['sort' => 'least_recent_order']],
        ];
    }

    public function test_an_unknown_sort_leaves_the_list_newest_first(): void
    {
        // Arrange
        $older = Customer::factory()->create();
        $newer = Customer::factory()->create();

        // Act
        $response = $this->withHeaders($this->auth())->getJson('/api/v1/customers?'.http_build_query(['sort' => 'قديم']));

        // Assert — a word this API does not know is not a reason to refuse a list; it is a
        // reason to answer the question it does understand.
        $ids = collect($response->json('data'))->pluck('id')->all();

        $response->assertOk();
        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_sorting_by_the_last_order_still_costs_one_query_for_the_counts(): void
    {
        // Arrange
        $customers = Customer::factory()->count(5)->create();
        foreach ($customers as $customer) {
            Order::factory()->forCustomer($customer)->create();
        }

        $headers = $this->auth();
        DB::enableQueryLog();

        // Act
        $this->withHeaders($headers)->getJson('/api/v1/customers?sort=least_recent_order')->assertOk();

        // Assert — two, whatever the page holds: the counts, and the page's own query carrying
        // the date as a correlated subquery. Never one per row, which is what a relation walked
        // per customer would have cost.
        $overOrders = collect(DB::getRawQueryLog())
            ->filter(fn (array $query): bool => str_contains($query['raw_query'], 'from "orders"'))
            ->values();

        DB::disableQueryLog();
        $this->assertCount(2, $overOrders);
    }
}
