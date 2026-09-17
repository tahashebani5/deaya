<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerShop;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Domain\Identity\AccessService;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\AdditionalCostReason;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The history endpoints — one per record, plus the global feed.
 *
 * All of them sit behind `logs.view`, which is deliberately *not* the permission that guards
 * the record: reading a history exposes what everyone has done, so someone allowed to edit
 * products is not automatically someone allowed to audit their colleagues. Several tests below
 * exist only to hold that line.
 *
 * Arrange - Act - Assert throughout.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are defined by the code, so the guards under test only exist once these
        // rows do.
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * A user holding exactly the permissions named.
     *
     * @return array<string, string>
     */
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** May read history. */
    private function auditor(): array
    {
        return $this->auth(PermissionName::ViewActivityLogs);
    }

    /**
     * An auditor, and a trail with nothing in it yet.
     *
     * Creating the auditor is itself an audited event — users are models like any other — so a
     * test that counts what the global feed returns has to start from a known-empty trail
     * rather than from "one entry I did not mean to make".
     *
     * @return array<string, string>
     */
    private function auditorWithEmptyTrail(): array
    {
        $headers = $this->auditor();

        DB::table('activity_log')->delete();

        return $headers;
    }

    /**
     * Drops the resolved auth guard.
     *
     * The container is reused for the whole test, so a guard that has already answered once
     * keeps returning the same user however the next request is authenticated. Any test making
     * two authenticated requests as different people has to call this between them.
     *
     * **It has to be the very last thing before the Act.** Recording an audit entry asks the
     * guard who the causer is, so *any* write after this line — creating the second user, even
     * granting them a permission — resolves the guard again and re-caches the first user. That
     * is not a quirk of the test: it is the trail doing its job on every write in the process.
     */
    private function forgetTheSignedInUser(): void
    {
        $this->app->get('auth')->forgetGuards();
    }

    // ─────────────────────────── happy paths ───────────────────────────

    public function test_a_products_history_lists_its_changes_newest_first(): void
    {
        // Arrange
        $product = Product::factory()->create(['name' => 'كيس أول']);
        $product->update(['name' => 'كيس ثانٍ']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/products/{$product->id}/logs");

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم بنجاح')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'updated')
            ->assertJsonPath('data.1.event', 'created')
            ->assertJsonPath('data.0.subject_type', 'product')
            ->assertJsonPath('data.0.subject_id', $product->id)
            ->assertJsonPath('data.0.changes.old.name', 'كيس أول')
            ->assertJsonPath('data.0.changes.attributes.name', 'كيس ثانٍ');
    }

    public function test_the_entry_carries_the_arabic_label_beside_every_code(): void
    {
        // Arrange
        $city = City::factory()->create();
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/logs");

        // Assert — the same bargain every enum in this API makes, so a client never keeps its
        // own translation table in step with ours.
        $response->assertOk()
            ->assertJsonPath('data.0.event_label', 'إنشاء')
            ->assertJsonPath('data.0.subject_type_label', 'مدينة')
            ->assertJsonPath('data.0.description', 'تم إنشاء مدينة');
    }

    public function test_every_column_in_an_entry_is_named_in_arabic(): void
    {
        // Arrange — the screen used to read `page_url`, `latitude`, `customer_id`. Those are
        // this schema's words, not a printing shop's.
        $customer = Customer::factory()->create();
        CustomerShop::factory()->create(['customer_id' => $customer->id, 'name' => 'فرع الظهرة']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/customers/{$customer->id}/logs");

        // Assert — the dictionary travels with the entry, so a column added tomorrow is
        // unlabelled everywhere at once rather than in one app nobody rebuilt.
        $response->assertOk();
        $shop = collect($response->json('data'))->firstWhere('subject_type', 'customer_shop');
        $this->assertNotNull($shop);
        $this->assertSame('اسم المحل', $shop['attribute_labels']['name']);
        $this->assertSame('رابط الصفحة', $shop['attribute_labels']['page_url']);
        $this->assertSame('خط العرض', $shop['attribute_labels']['latitude']);
    }

    public function test_the_same_column_on_two_records_is_named_for_the_record_it_is_on(): void
    {
        // Arrange — «اسم العميل» and «اسم المحل» sit in the same list on one screen, and one
        // «الاسم» twice would be the ambiguity the labels exist to remove.
        $customer = Customer::factory()->create();
        CustomerShop::factory()->create(['customer_id' => $customer->id]);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/customers/{$customer->id}/logs");

        // Assert
        $entries = collect($response->assertOk()->json('data'));
        $this->assertSame('اسم العميل', $entries->firstWhere('subject_type', 'customer')['attribute_labels']['name']);
        $this->assertSame('اسم المحل', $entries->firstWhere('subject_type', 'customer_shop')['attribute_labels']['name']);
    }

    public function test_only_the_columns_this_entry_touched_are_named(): void
    {
        // Arrange — the order dictionary alone is forty entries; sending every model's whole
        // vocabulary with each of fifteen rows would be most of the response.
        $customer = Customer::factory()->create();
        $customer->update(['phone' => '0915556666']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/customers/{$customer->id}/logs");

        // Assert
        $update = collect($response->assertOk()->json('data'))->firstWhere('event', 'updated');
        $this->assertSame(['phone' => 'رقم الهاتف'], $update['attribute_labels']);
    }

    public function test_an_unlabelled_column_is_absent_rather_than_guessed_at(): void
    {
        // Arrange — a column added to the schema and not to the dictionary. The client falls
        // back to the raw name, which is exactly what the screen showed before labels existed;
        // inventing one here would be worse than saying nothing.
        $city = City::factory()->create();
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/logs");

        // Assert
        $labels = $response->assertOk()->json('data.0.attribute_labels');
        $this->assertArrayHasKey('name', $labels);
        $this->assertArrayNotHasKey('id', $labels, 'the id is never logged, so it can never need a label');
    }

    public function test_every_value_in_an_entry_is_read_in_arabic_too(): void
    {
        // Arrange — the labels named the columns and the screen went on reading «الحالة: new».
        // An Arabic label in front of an English value is the half-translated line this closes.
        $order = Order::factory()->create(['status' => OrderStatus::New]);
        // Set on the model rather than mass assigned: `status` is not fillable, because the
        // status is moved by ChangeOrderStatus and nothing else. The trail records it either way.
        $order->status = OrderStatus::Printing;
        $order->save();
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}/logs");

        // Assert — both halves, because «من جديدة» is half the sentence.
        $update = collect($response->assertOk()->json('data'))->firstWhere('event', 'updated');
        $this->assertSame('جديدة', $update['value_labels']['old']['status']);
        $this->assertSame('قيد الطباعة', $update['value_labels']['attributes']['status']);
    }

    public function test_the_reason_for_an_additional_cost_is_read_in_arabic_too(): void
    {
        // Arrange — nothing was added to this file's own vocabulary for it. The column is cast
        // to an enum with a `label()`, which is the whole of what makes the history readable.
        $order = Order::factory()->create();
        $order->additional_cost = '10.00';
        $order->additional_cost_reason = AdditionalCostReason::SpecialPackaging;
        $order->save();
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}/logs");

        // Assert
        $update = collect($response->assertOk()->json('data'))->firstWhere('event', 'updated');
        $this->assertSame('تغليف خاص', $update['value_labels']['attributes']['additional_cost_reason']);
    }

    public function test_a_foreign_key_is_read_as_the_record_it_names(): void
    {
        // Arrange — «العميل: 12» named nothing to the person reading the screen.
        $customer = Customer::factory()->create(['name' => 'مطبعة النور']);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}/logs");

        // Assert
        $created = collect($response->assertOk()->json('data'))->firstWhere('event', 'created');
        $this->assertSame('مطبعة النور', $created['value_labels']['attributes']['customer_id']);
    }

    public function test_a_value_needing_no_translation_is_left_out_of_the_response(): void
    {
        // Arrange — a name is already Arabic and a phone is a number. Sending them back
        // untranslated would ship every row twice for nothing.
        $customer = Customer::factory()->create(['name' => 'مطبعة الأمل']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/customers/{$customer->id}/logs");

        // Assert — absent, and the client falls back to the raw value exactly as it does for an
        // unlabelled column.
        $labels = $response->assertOk()->json('data.0.value_labels.attributes');
        $this->assertArrayNotHasKey('name', $labels);
    }

    public function test_a_whole_page_of_entries_resolves_its_references_in_one_query_per_kind(): void
    {
        // Arrange — fifteen orders naming fifteen customers is the shape a feed actually has,
        // and the shape a per-entry lookup turns into forty round trips.
        $headers = $this->auditorWithEmptyTrail();

        foreach (Customer::factory()->count(15)->create() as $customer) {
            Order::factory()->create(['customer_id' => $customer->id]);
        }

        // Act
        DB::enableQueryLog();
        $response = $this->withHeaders($headers)->getJson('/api/v1/logs?per_page=50');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Assert — a ceiling rather than an exact number: the page also loads the auditor, the
        // entries and their causers, and pinning that count would make this test fail for
        // changes it is not about. What it will not survive is a lookup per entry.
        $response->assertOk();
        $this->assertLessThan(
            20,
            $queries,
            'a page of history must resolve its foreign keys in one query per kind, not one per entry',
        );
    }

    public function test_the_permissions_a_role_gained_are_named_in_arabic(): void
    {
        // Arrange — the most consequential edit the API allows, and the one that read worst:
        // a list of `products.view` strings.
        $role = Role::create(['name' => 'مصمم', 'guard_name' => 'web']);
        app(AccessService::class)->updateRole($role, 'مصمم', [PermissionName::ViewProducts->value]);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/roles/{$role->id}/logs");

        // Assert
        $entry = collect($response->assertOk()->json('data'))->firstWhere('event', 'updated');
        $this->assertSame(
            PermissionName::ViewProducts->label(),
            $entry['property_labels']['permissions'][PermissionName::ViewProducts->value],
        );
    }

    public function test_the_history_says_how_many_entries_of_each_kind_it_holds(): void
    {
        // Arrange — these numbers sit on the filter chips. Counted on the client they would be
        // a lie from page two onwards.
        $customer = Customer::factory()->create();
        $customer->update(['phone' => '0915556666']);
        $customer->update(['name' => 'مطبعة الأمل']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/customers/{$customer->id}/logs");

        // Assert
        $response->assertOk()
            ->assertJsonPath('meta.event_counts.created', 1)
            ->assertJsonPath('meta.event_counts.updated', 2)
            // Present at zero: a chip that appears and disappears is a control whose position
            // cannot be learnt.
            ->assertJsonPath('meta.event_counts.deleted', 0)
            ->assertJsonPath('meta.event_counts.restored', 0);
    }

    public function test_filtering_by_event_does_not_change_the_counts_on_the_other_chips(): void
    {
        // Arrange — applied to itself, every chip but the active one would read zero, which is
        // the opposite of what a filter control is for.
        $customer = Customer::factory()->create();
        $customer->update(['phone' => '0915556666']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/customers/{$customer->id}/logs?event=updated");

        // Assert — one row comes back, and the counts still describe the whole trail.
        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('updated', $response->json('data.0.event'));
        $response->assertJsonPath('meta.event_counts.created', 1)
            ->assertJsonPath('meta.event_counts.updated', 1);
    }

    public function test_the_counts_cover_the_records_this_one_owns(): void
    {
        // Arrange — a customer's history includes their shops, so the number under «إنشاء» has
        // to count those too or the chip disagrees with the list under it.
        $customer = Customer::factory()->create();
        CustomerShop::factory()->count(2)->create(['customer_id' => $customer->id]);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/customers/{$customer->id}/logs");

        // Assert
        $response->assertOk()->assertJsonPath('meta.event_counts.created', 3);
    }

    public function test_a_products_history_includes_its_sizes_prices_and_photos(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
        $tier = ProductPriceTier::factory()->create(['product_variant_id' => $variant->id]);
        $tier->update(['unit_price' => '0.900']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/products/{$product->id}/logs");

        // Assert — the price change lives on another table, and asking the client to know that
        // would push the shape of our schema into its code.
        $response->assertOk();
        $types = array_column($response->json('data'), 'subject_type');
        $this->assertContains('product', $types);
        $this->assertContains('product_variant', $types);
        $this->assertContains('product_price_tier', $types);
    }

    public function test_a_customers_history_includes_their_shops(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        CustomerShop::factory()->create(['customer_id' => $customer->id]);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/customers/{$customer->id}/logs");

        // Assert
        $response->assertOk();
        $this->assertContains('customer_shop', array_column($response->json('data'), 'subject_type'));
    }

    public function test_a_citys_history_includes_its_regions(): void
    {
        // Arrange
        $city = City::factory()->create();
        Region::factory()->create(['city_id' => $city->id]);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/logs");

        // Assert
        $response->assertOk();
        $this->assertContains('region', array_column($response->json('data'), 'subject_type'));
    }

    public function test_a_regions_history_is_only_its_own(): void
    {
        // Arrange
        $city = City::factory()->create();
        $region = Region::factory()->create(['city_id' => $city->id]);
        Region::factory()->create(['city_id' => $city->id]);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/regions/{$region->id}/logs");

        // Assert — the narrower endpoint stays narrow; the city's is the wider one.
        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame([$region->id], array_column($response->json('data'), 'subject_id'));
    }

    public function test_a_users_history_is_readable(): void
    {
        // Arrange
        $user = User::factory()->create(['name' => 'قديم']);
        $user->update(['name' => 'جديد']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/users/{$user->id}/logs");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.event', 'updated')
            ->assertJsonPath('data.0.changes.attributes.name', 'جديد');
    }

    public function test_a_roles_history_names_the_permissions_it_gained_and_lost(): void
    {
        // Arrange
        $access = app(AccessService::class);
        $role = $access->createRole('warehouse', ['products.view', 'customers.view']);
        $access->updateRole($role, 'warehouse', ['products.view']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/roles/{$role->id}/logs");

        // Assert — recorded by hand because the pivot table fires no model events, and it is the
        // most consequential edit this API allows.
        $response->assertOk()
            ->assertJsonPath('data.0.properties.permissions.granted', [])
            ->assertJsonPath('data.0.properties.permissions.revoked', ['customers.view']);
    }

    // ─────────────────────────── who did it ───────────────────────────

    public function test_the_entry_names_the_person_who_made_the_change(): void
    {
        // Arrange
        $editor = User::factory()->create(['name' => 'سالم']);
        $editor->givePermissionTo(PermissionName::ManageDeliveryLocations->value);
        $editorHeaders = ['Authorization' => 'Bearer '.$editor->createToken('test')->plainTextToken];
        $this->withHeaders($editorHeaders)->postJson('/api/v1/cities', ['name' => 'جديدة', 'delivery_price' => '10.00']);
        $city = City::query()->where('name', 'جديدة')->sole();
        $headers = $this->auditor();
        $this->forgetTheSignedInUser();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/logs");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.causer.id', $editor->id)
            ->assertJsonPath('data.0.causer.name', 'سالم')
            ->assertJsonPath('data.0.causer.type', 'user');
    }

    public function test_a_change_nobody_signed_in_made_reports_no_causer(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/products/{$product->id}/logs");

        // Assert — null rather than a placeholder, because inventing one would be a lie in the
        // only column an auditor reads.
        $response->assertOk()->assertJsonPath('data.0.causer', null);
    }

    public function test_the_history_of_a_deleted_record_is_still_readable(): void
    {
        // Arrange
        $headers = $this->auditorWithEmptyTrail();
        $city = City::factory()->create();
        $city->delete();

        // Act — the record is gone from `GET /cities/{city}`, and its history is exactly what
        // someone is looking for at that point. It is reached through the id it still has.
        $response = $this->withHeaders($headers)->getJson('/api/v1/logs?subject_type=city');

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['deleted', 'created'], array_column($response->json('data'), 'event'));
    }

    // ─────────────────────────── the global feed ───────────────────────────

    public function test_the_feed_returns_everything_newest_first(): void
    {
        // Arrange
        $headers = $this->auditorWithEmptyTrail();
        Product::factory()->create();
        City::factory()->create();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/logs');

        // Assert
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.subject_type', 'city')
            ->assertJsonPath('data.1.subject_type', 'product');
    }

    public function test_the_feed_can_be_narrowed_to_one_kind_of_record(): void
    {
        // Arrange
        $headers = $this->auditorWithEmptyTrail();
        Product::factory()->create();
        City::factory()->create();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/logs?subject_type=product');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject_type', 'product');
    }

    public function test_the_feed_can_be_narrowed_to_one_event(): void
    {
        // Arrange
        $headers = $this->auditorWithEmptyTrail();
        $city = City::factory()->create();
        $city->update(['name' => 'اسم آخر']);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/logs?event=updated');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.event', 'updated');
    }

    public function test_the_feed_can_be_narrowed_to_one_person(): void
    {
        // Arrange — order matters here. Nothing has authenticated yet, so the auditor and the
        // unattributed city are both written with no causer; only the request made as the
        // editor happens once a user is signed in.
        $headers = $this->auditorWithEmptyTrail();
        City::factory()->create(); // nobody's work

        $editor = User::factory()->create();
        $editor->givePermissionTo(PermissionName::ManageDeliveryLocations->value);
        $editorHeaders = ['Authorization' => 'Bearer '.$editor->createToken('test')->plainTextToken];
        $this->withHeaders($editorHeaders)->postJson('/api/v1/cities', ['name' => 'مدينته', 'delivery_price' => '10.00']);
        $this->forgetTheSignedInUser();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/logs?causer_id={$editor->id}");

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.causer.id', $editor->id);
    }

    public function test_a_date_range_includes_the_whole_of_its_last_day(): void
    {
        // Arrange
        $headers = $this->auditorWithEmptyTrail();
        City::factory()->create();
        $today = now()->toDateString();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/logs?from={$today}&to={$today}");

        // Assert — a bare `to` date parsed as midnight would exclude everything the day holds,
        // which is the opposite of what "including today" means.
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_range_that_ends_before_the_entries_returns_the_empty_set(): void
    {
        // Arrange
        $headers = $this->auditorWithEmptyTrail();
        City::factory()->create();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/logs?to=2020-01-01');

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    // ─────────────────────────── pagination ───────────────────────────

    public function test_the_feed_is_paginated(): void
    {
        // Arrange
        $headers = $this->auditorWithEmptyTrail();
        City::factory()->count(5)->create();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/logs?per_page=2');

        // Assert
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_an_absurd_per_page_is_clamped_rather_than_rejected(): void
    {
        // Arrange
        $headers = $this->auditorWithEmptyTrail();
        City::factory()->count(3)->create();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/logs?per_page=9999');

        // Assert — the same treatment every other list in this API gives it.
        $response->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_a_per_page_of_zero_falls_back_to_one(): void
    {
        // Arrange
        $headers = $this->auditorWithEmptyTrail();
        City::factory()->count(3)->create();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/logs?per_page=0');

        // Assert
        $response->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    public function test_a_record_with_no_history_returns_the_empty_set(): void
    {
        // Arrange — a city inserted without firing model events has nothing recorded about it.
        $city = City::factory()->create();
        DB::table('activity_log')->delete();
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/logs");

        // Assert — an empty page, not everything in the database, which is what an unguarded
        // "no subjects" query would have returned.
        $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    // ─────────────────────────── validation ───────────────────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'an event nobody can produce' => ['event=exploded', 'event'],
            'a subject type that does not exist' => ['subject_type=spaceship', 'subject_type'],
            'a date that is not a date' => ['from=not-a-date', 'from'],
            'a range that runs backwards' => ['from=2026-07-31&to=2026-07-01', 'to'],
            'a causer that is not an id' => ['causer_id=abc', 'causer_id'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_an_impossible_filter_is_rejected_rather_than_returning_nothing(string $query, string $field): void
    {
        // Arrange
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/logs?{$query}");

        // Assert — an empty page would read as "nothing happened", which is a different and
        // much more misleading answer than "your filter is wrong".
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonValidationErrors([$field]);
    }

    // ─────────────────────────── access ───────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function historyEndpoints(): array
    {
        return [
            'the feed' => ['/api/v1/logs'],
            'a product' => ['/api/v1/products/{product}/logs'],
            'a customer' => ['/api/v1/customers/{customer}/logs'],
            'a city' => ['/api/v1/cities/{city}/logs'],
            'a region' => ['/api/v1/cities/{city}/regions/{region}/logs'],
            'a user' => ['/api/v1/users/{user}/logs'],
            'a role' => ['/api/v1/roles/{role}/logs'],
        ];
    }

    #[DataProvider('historyEndpoints')]
    public function test_every_history_endpoint_refuses_an_unauthenticated_caller(string $template): void
    {
        // Arrange
        $url = $this->fill($template);

        // Act
        $response = $this->getJson($url);

        // Assert
        $response->assertStatus(401)->assertJsonPath('message', 'غير مصرح لك بالدخول');
    }

    #[DataProvider('historyEndpoints')]
    public function test_every_history_endpoint_refuses_a_caller_without_the_permission(string $template): void
    {
        // Arrange
        $url = $this->fill($template);
        // Deliberately holds *every other* permission in the catalogue: being allowed to edit
        // the records is not being allowed to audit who edited them.
        $everythingElse = array_filter(
            PermissionName::cases(),
            fn (PermissionName $p) => $p !== PermissionName::ViewActivityLogs,
        );
        $headers = $this->auth(...$everythingElse);

        // Act
        $response = $this->withHeaders($headers)->getJson($url);

        // Assert
        $response->assertStatus(403)->assertJsonPath('message', 'ليس لديك صلاحية لتنفيذ هذا الإجراء');
    }

    public function test_an_administrator_reads_history_without_being_granted_anything(): void
    {
        // Arrange
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('test')->plainTextToken];
        $city = City::factory()->create();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/cities/{$city->id}/logs");

        // Assert — the gate in AppServiceProvider, not a permission row.
        $response->assertOk();
    }

    // ─────────────────────────── not found ───────────────────────────

    public function test_asking_for_the_history_of_a_record_that_does_not_exist_is_a_404(): void
    {
        // Arrange
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/products/999999/logs');

        // Assert
        $response->assertNotFound()->assertJsonPath('message', 'العنصر المطلوب غير موجود');
    }

    public function test_a_region_from_another_city_is_a_404_here_too(): void
    {
        // Arrange
        $city = City::factory()->create();
        $foreignRegion = Region::factory()->create(['city_id' => City::factory()->create()->id]);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/cities/{$city->id}/regions/{$foreignRegion->id}/logs");

        // Assert — the scoped binding on the route, which is the whole reason these endpoints
        // hang off the record rather than living at one /logs URL.
        $response->assertNotFound();
    }

    /**
     * Replaces `{model}` placeholders in a route template with a real record's id.
     */
    private function fill(string $template): string
    {
        $city = City::factory()->create();

        return str_replace(
            ['{product}', '{customer}', '{city}', '{region}', '{user}', '{role}'],
            [
                (string) Product::factory()->create()->id,
                (string) Customer::factory()->create()->id,
                (string) $city->id,
                (string) Region::factory()->create(['city_id' => $city->id])->id,
                (string) User::factory()->create()->id,
                (string) Role::findOrCreate('temp', 'web')->id,
            ],
            $template,
        );
    }
}
