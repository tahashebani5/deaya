<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductPriceTier;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Delivery\Models\City;
use App\Domain\Identity\AccessService;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What the audit trail records, and what it deliberately does not.
 *
 * These are model-level tests: they call `save()` and `delete()` rather than endpoints, because
 * the guarantee being made is that *any* code path writes history — not just the ones that go
 * through a controller today.
 *
 * Arrange - Act - Assert throughout.
 */
class ActivityLoggingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The most recent entry about a record.
     */
    private function lastEntryFor(object $model): ?ActivityLog
    {
        return ActivityLog::query()
            ->where('subject_type', $model->getMorphClass())
            ->where('subject_id', $model->getKey())
            ->orderByDesc('id')
            ->first();
    }

    // ─────────────────────────── the four events ───────────────────────────

    public function test_creating_a_record_writes_a_created_entry(): void
    {
        // Arrange & Act
        $product = Product::factory()->create(['name' => 'كيس شحن']);

        // Assert
        $entry = $this->lastEntryFor($product);
        $this->assertNotNull($entry);
        $this->assertSame(AuditEvent::Created->value, $entry->event);
        $this->assertSame('product', $entry->subject_type);
        $this->assertSame($product->id, $entry->subject_id);
        $this->assertSame('كيس شحن', $entry->attribute_changes?->get('attributes')['name'] ?? null);
    }

    public function test_updating_a_record_writes_both_sides_of_the_change(): void
    {
        // Arrange
        $customer = Customer::factory()->create(['name' => 'مخبز قديم']);

        // Act
        $customer->update(['name' => 'مخبز جديد']);

        // Assert
        $entry = $this->lastEntryFor($customer);
        $this->assertSame(AuditEvent::Updated->value, $entry?->event);
        $this->assertSame('مخبز قديم', $entry?->attribute_changes?->get('old')['name'] ?? null);
        $this->assertSame('مخبز جديد', $entry?->attribute_changes?->get('attributes')['name'] ?? null);
    }

    public function test_deleting_a_record_writes_a_deleted_entry_holding_what_was_lost(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'ودان']);

        // Act
        $city->delete();

        // Assert — a `deleted` entry carries the whole row as `old`, which is what makes the
        // trail able to answer "what was in it" after it is gone.
        $entry = $this->lastEntryFor($city);
        $this->assertSame(AuditEvent::Deleted->value, $entry?->event);
        $this->assertSame('ودان', $entry?->attribute_changes?->get('old')['name'] ?? null);
    }

    public function test_restoring_a_record_writes_a_restored_entry(): void
    {
        // Arrange
        $city = City::factory()->create();
        $city->delete();

        // Act
        $city->restore();

        // Assert — an event only reachable because the model soft deletes.
        $entry = $this->lastEntryFor($city);
        $this->assertSame(AuditEvent::Restored->value, $entry?->event);
    }

    public function test_restoring_writes_one_entry_rather_than_also_an_update(): void
    {
        // Arrange
        $city = City::factory()->create();
        $city->delete();

        // Act
        $city->restore();

        // Assert — `restore()` saves the model, so a naive implementation records an `updated`
        // for the deleted_at column alongside the `restored`. One event, one entry.
        $entries = ActivityLog::query()
            ->where('subject_type', $city->getMorphClass())
            ->where('subject_id', $city->getKey())
            ->pluck('event')
            ->all();

        $this->assertSame(['created', 'deleted', 'restored'], $entries);
    }

    // ─────────────────────────── what is left out ───────────────────────────

    public function test_saving_a_record_without_changing_anything_writes_nothing(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $before = ActivityLog::query()->count();

        // Act
        $product->update(['name' => $product->name]);

        // Assert — re-sending identical values is not an event.
        $this->assertSame($before, ActivityLog::query()->count());
    }

    public function test_an_update_records_only_the_fields_that_moved(): void
    {
        // Arrange
        $product = Product::factory()->create(['name' => 'قديم', 'sort_order' => 3]);

        // Act
        $product->update(['name' => 'جديد']);

        // Assert
        $changed = $this->lastEntryFor($product)?->attribute_changes?->get('attributes') ?? [];
        $this->assertSame(['name'], array_keys($changed));
    }

    public function test_a_password_never_reaches_the_audit_trail(): void
    {
        // Arrange & Act
        $user = User::factory()->create();

        // Assert — excluded globally in config, so no individual model can forget to.
        $recorded = $this->lastEntryFor($user)?->attribute_changes?->get('attributes') ?? [];
        $this->assertArrayNotHasKey('password', $recorded);
        $this->assertArrayNotHasKey('remember_token', $recorded);
        $this->assertArrayHasKey('email', $recorded);
    }

    public function test_timestamps_are_left_out_of_the_recorded_change(): void
    {
        // Arrange & Act
        $city = City::factory()->create();

        // Assert — they are already on the log row itself.
        $recorded = $this->lastEntryFor($city)?->attribute_changes?->get('attributes') ?? [];
        $this->assertArrayNotHasKey('created_at', $recorded);
        $this->assertArrayNotHasKey('updated_at', $recorded);
        $this->assertArrayNotHasKey('deleted_at', $recorded);
    }

    // ─────────────────────────── who did it ───────────────────────────

    public function test_the_signed_in_user_is_recorded_as_the_causer(): void
    {
        // Arrange
        $user = User::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
        $user->givePermissionTo($this->permission('customers.manage'));

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/customers', [
            'name' => 'مطعم البحر',
            'phone' => '0912345678',
        ]);

        // Assert
        $response->assertCreated();
        $customer = Customer::query()->where('phone', '0912345678')->sole();
        $entry = $this->lastEntryFor($customer);
        $this->assertSame('user', $entry?->causer_type);
        $this->assertSame($user->id, $entry?->causer_id);
    }

    public function test_work_no_person_did_is_recorded_without_a_causer(): void
    {
        // Arrange & Act — a factory is the console's equivalent: nobody is signed in.
        $product = Product::factory()->create();

        // Assert — null rather than a placeholder user, because inventing one would be a lie
        // in the only column an auditor actually reads.
        $entry = $this->lastEntryFor($product);
        $this->assertNull($entry?->causer_id);
        $this->assertNull($entry?->causer_type);
    }

    // ─────────────────────────── shape of the row ───────────────────────────

    public function test_the_subject_is_stored_as_an_alias_not_a_class_name(): void
    {
        // Arrange & Act
        $variant = ProductVariant::factory()->create();

        // Assert — the value is published to clients and lives in the database forever, so it
        // must not be a PHP namespace.
        $entry = $this->lastEntryFor($variant);
        $this->assertSame('product_variant', $entry?->subject_type);
        $this->assertStringNotContainsString('App\\', (string) $entry?->subject_type);
    }

    public function test_the_description_is_an_arabic_sentence_naming_the_kind_of_record(): void
    {
        // Arrange & Act
        $city = City::factory()->create();

        // Assert
        $this->assertSame('تم إنشاء مدينة', $this->lastEntryFor($city)?->description);
    }

    public function test_the_log_name_groups_entries_by_the_kind_of_record(): void
    {
        // Arrange & Act
        $tier = ProductPriceTier::factory()->create();

        // Assert
        $this->assertSame('product_price_tier', $this->lastEntryFor($tier)?->log_name);
    }

    public function test_the_trail_survives_the_record_it_describes(): void
    {
        // Arrange
        $city = City::factory()->create(['name' => 'براك']);
        $cityId = $city->id;

        // Act
        $city->delete();

        // Assert — the whole point: history outlives the row, and the subject still resolves
        // because the row is only soft deleted.
        $entries = ActivityLog::query()
            ->with('subject')
            ->where('subject_type', 'city')
            ->where('subject_id', $cityId)
            ->get();

        $this->assertCount(2, $entries);
        $this->assertNotNull($entries->last()?->subject);
    }

    // ─────────────────────────── role permissions ───────────────────────────

    public function test_granting_a_permission_to_a_role_is_recorded_by_hand(): void
    {
        // Arrange
        $this->seedPermissions();
        $role = app(AccessService::class)->createRole('warehouse', []);

        // Act
        app(AccessService::class)->updateRole($role, 'warehouse', ['products.view']);

        // Assert — `role_has_permissions` is a pivot table with no model events, so this entry
        // exists only because UpdateRole writes it.
        $entry = $this->lastEntryFor($role);
        $this->assertSame(AuditEvent::Updated->value, $entry?->event);
        $this->assertSame(['products.view'], $entry?->getProperty('permissions.granted'));
        $this->assertSame([], $entry?->getProperty('permissions.revoked'));
    }

    public function test_revoking_a_permission_from_a_role_is_recorded(): void
    {
        // Arrange
        $this->seedPermissions();
        $access = app(AccessService::class);
        $role = $access->createRole('warehouse', ['products.view', 'customers.view']);

        // Act
        $access->updateRole($role, 'warehouse', ['products.view']);

        // Assert
        $entry = $this->lastEntryFor($role);
        $this->assertSame(['customers.view'], $entry?->getProperty('permissions.revoked'));
    }

    public function test_syncing_the_same_permissions_again_records_nothing(): void
    {
        // Arrange
        $this->seedPermissions();
        $access = app(AccessService::class);
        $role = $access->createRole('warehouse', ['products.view']);
        $before = ActivityLog::query()->count();

        // Act
        $access->updateRole($role, 'warehouse', ['products.view']);

        // Assert — the same restraint `logOnlyDirty` applies to a column.
        $this->assertSame($before, ActivityLog::query()->count());
    }

    public function test_a_role_is_audited_like_every_other_model(): void
    {
        // Arrange
        $this->seedPermissions();
        $role = Role::create(['name' => 'auditor', 'guard_name' => 'web']);

        // Act
        $role->update(['name' => 'inspector']);

        // Assert
        $entry = $this->lastEntryFor($role);
        $this->assertSame('role', $entry?->subject_type);
        $this->assertSame('auditor', $entry?->attribute_changes?->get('old')['name'] ?? null);
        $this->assertSame('inspector', $entry?->attribute_changes?->get('attributes')['name'] ?? null);
    }

    private function seedPermissions(): void
    {
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    private function permission(string $name): string
    {
        Permission::findOrCreate($name, 'web');

        return $name;
    }
}
