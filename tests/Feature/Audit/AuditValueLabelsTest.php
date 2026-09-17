<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditAttributeLabels;
use App\Domain\Audit\AuditReferenceNames;
use App\Domain\Audit\AuditValueLabels;
use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Order\Models\Order;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The other half of the history screen: what the values *say*.
 *
 * {@see AuditAttributeLabels} named the columns — `page_url` became «رابط
 * الصفحة». The values stayed as the database wrote them, so a screen that says «الحالة» in
 * Arabic went on to say `printing` in English, while the very same status reads «قيد الطباعة»
 * on the order card three taps away.
 *
 * The rule under test is that a value is translated by **the thing that already knows its
 * type** — the model's own `casts()` — and never by a second dictionary kept by hand. The
 * dictionary would be wrong the first morning somebody adds an enum, with no build failing to
 * say so; `casts()` is right that same morning because the developer had to write it to store
 * the column at all.
 *
 * Arrange - Act - Assert throughout.
 */
class AuditValueLabelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_enum_value_arrives_in_arabic(): void
    {
        // Arrange — the shape a status change actually has in the trail.
        $old = ['status' => 'new'];
        $new = ['status' => 'printing'];

        // Act
        $labels = AuditValueLabels::forChanges(AuditSubject::Order, $old, $new);

        // Assert — both halves, because «من جديدة» is half the sentence.
        $this->assertSame(['status' => 'جديدة'], $labels['old']);
        $this->assertSame(['status' => 'قيد الطباعة'], $labels['attributes']);
    }

    public function test_the_rule_holds_for_any_model_not_just_orders(): void
    {
        // Arrange — a different context entirely, and nothing was added to make it work.
        $new = ['pricing_mode' => 'quote_on_request', 'name' => 'أكياس ورقية'];

        // Act
        $labels = AuditValueLabels::forChanges(AuditSubject::Product, [], $new);

        // Assert
        $this->assertSame('السعر حسب الطلب', $labels['attributes']['pricing_mode']);
    }

    public function test_a_value_needing_no_translation_is_left_alone(): void
    {
        // Arrange — a name is already Arabic, a price is a number, and «نعم/لا» the app says
        // itself. Translating them would mean shipping the whole row twice.
        $new = ['name' => 'أكياس ورقية', 'grand_total' => '450.00', 'is_active' => true];

        // Act
        $labels = AuditValueLabels::forChanges(AuditSubject::Product, [], $new);

        // Assert — absent, not empty-string: the client falls back to the raw value.
        $this->assertArrayNotHasKey('name', $labels['attributes']);
        $this->assertArrayNotHasKey('grand_total', $labels['attributes']);
        $this->assertArrayNotHasKey('is_active', $labels['attributes']);
    }

    public function test_an_unknown_enum_value_falls_back_rather_than_failing(): void
    {
        // Arrange — a row written by an older build, holding a case this one has since dropped.
        $new = ['status' => 'a_status_we_no_longer_have'];

        // Act
        $labels = AuditValueLabels::forChanges(AuditSubject::Order, [], $new);

        // Assert — history must stay readable when the code moves on past it.
        $this->assertArrayNotHasKey('status', $labels['attributes']);
    }

    public function test_nothing_is_claimed_about_a_subject_we_do_not_know(): void
    {
        // Arrange & Act
        $labels = AuditValueLabels::forChanges(null, ['status' => 'new'], ['status' => 'ready']);

        // Assert
        $this->assertSame([], $labels['old']);
        $this->assertSame([], $labels['attributes']);
    }

    public function test_a_foreign_key_is_read_as_the_record_it_names(): void
    {
        // Arrange — `customer_id: 12` is a number nobody in the workshop can read.
        $customer = Customer::factory()->create(['name' => 'مطبعة النور']);
        $names = AuditReferenceNames::resolve([Customer::class => [$customer->getKey()]]);

        // Act
        $labels = AuditValueLabels::forChanges(
            AuditSubject::Order,
            [],
            ['customer_id' => $customer->getKey()],
            $names,
        );

        // Assert
        $this->assertSame('مطبعة النور', $labels['attributes']['customer_id']);
    }

    public function test_a_deleted_record_still_has_a_name_in_the_history(): void
    {
        // Arrange — the trail outliving its subjects is the whole point of keeping one.
        $customer = Customer::factory()->create(['name' => 'مطبعة النور']);
        $id = $customer->getKey();
        $customer->delete();

        // Act
        $names = AuditReferenceNames::resolve([Customer::class => [$id]]);

        // Assert
        $this->assertSame('مطبعة النور', $names->nameFor(Customer::class, $id));
    }

    public function test_a_reference_that_resolves_to_nothing_leaves_the_raw_id(): void
    {
        // Arrange — a hard-deleted row, or one from a database this trail outlived.
        $names = AuditReferenceNames::resolve([Customer::class => [9_999_999]]);

        // Act
        $labels = AuditValueLabels::forChanges(
            AuditSubject::Order,
            [],
            ['customer_id' => 9_999_999],
            $names,
        );

        // Assert — an id showing as an id is what the screen did before this existed; an
        // invented «غير معروف» would look like data.
        $this->assertArrayNotHasKey('customer_id', $labels['attributes']);
    }

    public function test_permissions_are_named_in_arabic(): void
    {
        // Arrange — the most consequential edit the API allows, and the one that reads worst.
        $properties = ['permissions' => [
            'granted' => [PermissionName::ViewProducts->value],
            'revoked' => [PermissionName::ManageRoles->value],
        ]];

        // Act
        $labels = AuditValueLabels::forProperties($properties);

        // Assert — keyed by the value itself, which is unambiguous here in a way a foreign
        // key's `12` never is.
        $this->assertSame(
            PermissionName::ViewProducts->label(),
            $labels['permissions'][PermissionName::ViewProducts->value],
        );
        $this->assertSame(
            PermissionName::ManageRoles->label(),
            $labels['permissions'][PermissionName::ManageRoles->value],
        );
    }

    public function test_the_whole_page_of_references_costs_one_query_per_kind(): void
    {
        // Arrange — fifteen entries naming fifteen different customers, which is the shape a
        // history page actually has and the shape a naive resolver turns into fifteen queries.
        $ids = Customer::factory()->count(15)->create()->modelKeys();

        // Act
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $names = AuditReferenceNames::resolve([Customer::class => $ids]);

        // Assert
        $this->assertSame(1, $queries);
        $this->assertSame(15, count(array_filter(array_map(
            fn (int $id) => $names->nameFor(Customer::class, $id),
            $ids,
        ))));
    }

    /**
     * The guard that makes the rule a rule.
     *
     * A new enum reaches the history screen the moment it is cast on a model. If it cannot name
     * itself, the screen quietly prints its English case — which is exactly the state this work
     * set out to end, reappearing one column at a time.
     */
    public function test_every_enum_a_model_stores_can_name_itself_in_arabic(): void
    {
        // Arrange
        $offenders = [];

        // Act
        foreach (AuditSubject::cases() as $subject) {
            /** @var Model $model */
            $model = new ($subject->modelClass());

            foreach ($model->getCasts() as $column => $cast) {
                if (! is_string($cast) || ! enum_exists($cast)) {
                    continue;
                }

                if (! is_subclass_of($cast, BackedEnum::class) || ! method_exists($cast, 'label')) {
                    $offenders[] = $subject->value.'.'.$column.' ('.$cast.')';
                }
            }
        }

        // Assert
        $this->assertSame([], $offenders, implode("\n", [
            'Every enum stored on a model reaches the history screen, so every one of them needs',
            'a `label()` returning Arabic. Without it the screen prints the English case:',
            ...$offenders,
        ]));
    }

    public function test_a_reference_column_points_at_a_relation_that_exists(): void
    {
        // Arrange — the mapping from `customer_id` to the customer is *derived* from the
        // model's own `belongsTo`, so this asserts the derivation still finds one.
        $subject = AuditSubject::Order;

        // Act
        $references = AuditValueLabels::referencesFor($subject, ['customer_id', 'city_id', 'code']);

        // Assert — and `code` is not a reference, which is the half that would break silently.
        $this->assertSame(Customer::class, $references['customer_id'] ?? null);
        $this->assertArrayNotHasKey('code', $references);
    }

    public function test_a_record_with_no_name_column_is_read_by_its_code(): void
    {
        // Arrange — an order has no `name`; «طلبية» in a history line has to be its code.
        $order = Order::factory()->create();

        // Act
        $names = AuditReferenceNames::resolve([Order::class => [$order->getKey()]]);

        // Assert
        $this->assertSame($order->code, $names->nameFor(Order::class, $order->getKey()));
    }

    public function test_the_product_a_variant_belongs_to_is_named(): void
    {
        // Arrange — a rule that only worked for the columns it was written against would not be
        // a rule, so this exercises a second subject and a second model.
        $product = Product::factory()->create(['name' => 'أكياس ورقية']);
        $names = AuditReferenceNames::resolve([Product::class => [$product->getKey()]]);

        // Act
        $labels = AuditValueLabels::forChanges(
            AuditSubject::ProductVariant,
            [],
            ['product_id' => $product->getKey()],
            $names,
        );

        // Assert
        $this->assertSame('أكياس ورقية', $labels['attributes']['product_id']);
    }
}
