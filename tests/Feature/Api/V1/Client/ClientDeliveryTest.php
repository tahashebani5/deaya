<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Client;

use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a customer may send an order.
 *
 * **This endpoint exists because `RequestOrderRequest` requires `city_id`.** Without it the app
 * would have to name a destination it has no way to list — so the picker is readable by every
 * signed-in customer and by no permission at all, and what it may show is decided by
 * `ClientCityResource`'s field list.
 *
 * The absences asserted below are the point of the separate resource: `darb_branch` and
 * `nawris_government_id` name the carrier we hand a destination to, which is an arrangement
 * between the shop and them. A field that tells every customer also tells every competitor.
 *
 * Arrange - Act - Assert throughout.
 */
class ClientDeliveryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function bearer(?Customer $customer = null): array
    {
        $customer ??= Customer::factory()->registered()->create();

        return ['Authorization' => 'Bearer '.$customer->createToken('test-device')->plainTextToken];
    }

    public function test_a_customer_can_read_where_we_deliver(): void
    {
        $city = City::factory()->create(['name' => 'مصراتة']);

        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/cities');

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonFragment(['id' => $city->id, 'name' => 'مصراتة']);
    }

    public function test_a_city_arrives_with_its_regions_so_the_picker_needs_one_call(): void
    {
        $city = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $city->id, 'name' => 'وسط البلاد']);

        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/cities');

        $response->assertOk()
            ->assertJsonPath('data.0.regions.0.id', $region->id)
            ->assertJsonPath('data.0.regions.0.name', 'وسط البلاد')
            ->assertJsonPath('data.0.regions.0.city_id', $city->id);
    }

    /**
     * **The one deliberate inclusion.** What delivery costs is a number the customer pays, and a
     * picker that hides it is a picker that surprises somebody at the end of a checkout. It is a
     * string for the reason every amount in this API is one — `15.0` is not `"15.00"` once a
     * client's JSON parser has been through it.
     */
    public function test_the_delivery_price_is_sent_as_a_string(): void
    {
        City::factory()->create(['delivery_price' => '15.00']);

        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/cities');

        $response->assertOk();
        $this->assertSame('15.00', $response->json('data.0.delivery_price'));
    }

    /**
     * Null is not «free». A city with no agreed rate and a city that delivers for nothing are
     * different answers, and the app has to be able to draw them differently.
     */
    public function test_a_city_with_no_agreed_rate_sends_null_rather_than_zero(): void
    {
        City::factory()->create(['delivery_price' => null]);

        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/cities');

        $response->assertOk();
        $this->assertNull($response->json('data.0.delivery_price'));
    }

    /**
     * The customer app never learns which carrier serves a destination.
     */
    public function test_the_picker_never_carries_our_carrier_arrangements(): void
    {
        City::factory()->create(['darb_branch' => 'فرع طرابلس', 'nawris_government_id' => 7]);

        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/cities');

        $response->assertOk();

        $city = $response->json('data.0');

        $this->assertArrayNotHasKey('darb_branch', $city);
        $this->assertArrayNotHasKey('nawris_government_id', $city);
        $this->assertArrayNotHasKey('latitude', $city);
        $this->assertArrayNotHasKey('longitude', $city);
        $this->assertArrayNotHasKey('created_at', $city);
    }

    /**
     * Nor does a region.
     */
    public function test_a_region_never_carries_them_either(): void
    {
        $city = City::factory()->create();
        Region::factory()->create([
            'city_id' => $city->id,
            'darb_branch' => 'فرع طرابلس',
            'nawris_area_id' => 12,
        ]);

        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/cities');

        $response->assertOk();

        $region = $response->json('data.0.regions.0');

        $this->assertArrayNotHasKey('darb_branch', $region);
        $this->assertArrayNotHasKey('nawris_area_id', $region);
        $this->assertArrayNotHasKey('code', $region);
    }

    /**
     * The three fields the order screen branches on. Sent decided, so the app keeps no table of
     * its own translating an enum it would then have to keep in step with the business.
     */
    public function test_the_fulfilment_type_arrives_decided(): void
    {
        City::factory()->create();

        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/cities');

        $response->assertOk();

        $city = $response->json('data.0');

        $this->assertArrayHasKey('fulfilment_type', $city);
        $this->assertArrayHasKey('fulfilment_type_label', $city);
        $this->assertIsBool($city['is_office_pickup']);
        $this->assertIsBool($city['is_region_required']);
    }

    /**
     * Insertion order *is* the business's order — the office-pickup branches were seeded first
     * and belong at the top of the picker. Sorting Arabic names alphabetically would bury them
     * and would depend on the database's collation.
     */
    public function test_the_picker_keeps_the_order_the_business_put_them_in(): void
    {
        $first = City::factory()->create(['name' => 'ي']);
        $second = City::factory()->create(['name' => 'أ']);

        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/cities');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);
    }

    public function test_a_guest_is_refused(): void
    {
        City::factory()->create();

        $this->getJson('/api/v1/client/cities')->assertUnauthorized();
    }

    /**
     * The wall between the two apps. A staff token is issued against the `users` provider and
     * the `customer` guard names `customers` — so it does not matter what permissions the user
     * holds, the token cannot satisfy this guard at all.
     */
    public function test_a_staff_token_cannot_read_the_customer_picker(): void
    {
        City::factory()->create();
        $staff = User::factory()->create();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$staff->createToken('staff-device')->plainTextToken,
        ])->getJson('/api/v1/client/cities')->assertUnauthorized();
    }

    /**
     * A soft-deleted city is a destination the business has withdrawn, and the picker must not
     * offer it — an order named against one would be an order for somewhere we no longer go.
     */
    public function test_a_withdrawn_destination_leaves_the_picker(): void
    {
        $live = City::factory()->create();
        $gone = City::factory()->create();
        $gone->delete();

        $response = $this->withHeaders($this->bearer())->getJson('/api/v1/client/cities');

        $response->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($live->id, $ids);
        $this->assertNotContains($gone->id, $ids);
    }
}
