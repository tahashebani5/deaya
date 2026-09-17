<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Vendor\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Vendors — the suppliers the business buys stock from.
 *
 * Two permissions guard them: `vendors.view` to read the list, `vendors.manage` to change it.
 * Both are declared on the routes, so these tests exercise the guard exactly as a client meets it.
 *
 * Arrange - Act - Assert throughout.
 */
class VendorTest extends TestCase
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
        return $this->auth(PermissionName::ViewVendors);
    }

    /** @return array<string, string> */
    private function manager(): array
    {
        return $this->auth(PermissionName::ViewVendors, PermissionName::ManageVendors);
    }

    /** Authenticated, but granted nothing at all. @return array<string, string> */
    private function outsider(): array
    {
        return $this->auth();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'شركة الوريد للتوريدات',
            'contact_person' => 'محمد علي',
            'phone' => '0912345678',
            'email' => 'vendor@example.com',
            'address' => 'طرابلس',
        ], $overrides);
    }

    // ──────────────────────────────── listing ────────────────────────────────

    public function test_a_user_with_view_permission_can_list_the_vendors(): void
    {
        // Arrange
        Vendor::factory()->create(['name' => 'المورد الرئيسي']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/vendors');

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.0.name', 'المورد الرئيسي')
            ->assertJsonStructure([
                'status', 'message',
                'data' => [['id', 'name', 'contact_person', 'phone', 'email', 'address', 'is_active']],
                'meta' => ['current_page', 'per_page', 'last_page', 'total'],
            ]);
    }

    public function test_listing_the_vendors_needs_authentication(): void
    {
        // Act
        $response = $this->getJson('/api/v1/vendors');

        // Assert
        $response->assertUnauthorized()->assertJsonPath('status', false);
    }

    public function test_a_user_granted_nothing_may_not_list_the_vendors(): void
    {
        // Arrange
        Vendor::factory()->create();
        $headers = $this->outsider();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/vendors');

        // Assert
        $response->assertForbidden()->assertJsonPath('status', false);
    }

    public function test_the_vendor_list_is_paginated(): void
    {
        // Arrange
        Vendor::factory()->count(20)->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/vendors?per_page=5&page=2');

        // Assert
        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 20);
    }

    public function test_the_list_can_be_searched_by_name(): void
    {
        // Arrange
        Vendor::factory()->create(['name' => 'مصنع الأكياس']);
        Vendor::factory()->create(['name' => 'شركة الطباعة']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/vendors?search='.urlencode('الأكياس'));

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'مصنع الأكياس');
    }

    public function test_the_list_can_be_filtered_by_active_status(): void
    {
        // Arrange
        Vendor::factory()->create(['name' => 'نشط']);
        Vendor::factory()->inactive()->create(['name' => 'غير نشط']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/vendors?is_active=0');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'غير نشط');
    }

    public function test_a_soft_deleted_vendor_is_absent_from_the_list(): void
    {
        // Arrange
        Vendor::factory()->create(['name' => 'باقٍ']);
        Vendor::factory()->create(['name' => 'محذوف'])->delete();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/vendors');

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'باقٍ');
    }

    // ──────────────────────────────── reading one ────────────────────────────────

    public function test_a_viewer_can_read_one_vendor(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create(['name' => 'المورد الرئيسي']);
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/vendors/{$vendor->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.id', $vendor->id)
            ->assertJsonPath('data.name', 'المورد الرئيسي');
    }

    public function test_reading_a_vendor_that_does_not_exist_is_a_404(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/vendors/999999');

        // Assert
        $response->assertNotFound()->assertJsonPath('status', false);
    }

    // ──────────────────────────────── creating ────────────────────────────────

    public function test_a_manager_can_create_a_vendor(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/vendors', $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم إضافة المورد بنجاح')
            ->assertJsonPath('data.name', 'شركة الوريد للتوريدات')
            ->assertJsonPath('data.phone', '0912345678')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('vendors', ['phone' => '0912345678', 'name' => 'شركة الوريد للتوريدات']);
    }

    public function test_a_viewer_may_not_create_a_vendor(): void
    {
        // Arrange
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/vendors', $this->payload());

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseCount('vendors', 0);
    }

    public function test_creating_a_vendor_requires_a_name_and_phone(): void
    {
        // Arrange
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/vendors', []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonStructure(['errors' => ['name', 'phone']]);
    }

    public function test_a_vendor_phone_must_be_unique(): void
    {
        // Arrange
        Vendor::factory()->create(['phone' => '0912345678']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/vendors', $this->payload(['phone' => '0912345678']));

        // Assert
        $response->assertStatus(422)->assertJsonStructure(['errors' => ['phone']]);
    }

    public function test_a_soft_deleted_vendors_phone_can_be_reused(): void
    {
        // Arrange — the partial unique index ignores trashed rows, and validation must agree
        Vendor::factory()->create(['phone' => '0912345678'])->delete();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/vendors', $this->payload(['phone' => '0912345678']));

        // Assert
        $response->assertCreated();
    }

    // ──────────────────────────────── updating ────────────────────────────────

    public function test_a_manager_can_update_a_vendor(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create(['name' => 'قديم']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/vendors/{$vendor->id}",
            $this->payload(['name' => 'جديد']),
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.name', 'جديد');
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'name' => 'جديد']);
    }

    public function test_updating_a_vendor_may_keep_its_own_phone(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create(['phone' => '0912345678']);
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->putJson(
            "/api/v1/vendors/{$vendor->id}",
            $this->payload(['phone' => '0912345678']),
        );

        // Assert
        $response->assertOk();
    }

    public function test_updating_a_vendor_leaves_its_active_status_untouched_when_omitted(): void
    {
        // Arrange
        $vendor = Vendor::factory()->inactive()->create();
        $headers = $this->manager();

        // Act — is_active is not part of the update payload
        $response = $this->withHeaders($headers)->putJson("/api/v1/vendors/{$vendor->id}", $this->payload());

        // Assert
        $response->assertOk()->assertJsonPath('data.is_active', false);
    }

    // ──────────────────────────────── activation ────────────────────────────────

    public function test_a_manager_can_deactivate_a_vendor(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $headers = $this->manager();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/vendors/{$vendor->id}/activation",
            ['is_active' => false],
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('vendors', ['id' => $vendor->id, 'is_active' => false]);
    }

    public function test_a_viewer_may_not_change_a_vendors_activation(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $headers = $this->viewer();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/vendors/{$vendor->id}/activation",
            ['is_active' => false],
        );

        // Assert
        $response->assertForbidden();
    }
}
