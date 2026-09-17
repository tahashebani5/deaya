<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Creating roles and granting them permissions — the administrator's job.
 *
 * Arrange - Act - Assert throughout.
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([RoleName::Admin->value]);

        return $user;
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->syncRoles([RoleName::Staff->value]);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function tokenFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * Drop the resolved user from the auth guards.
     *
     * The container is reused across requests inside one test, so once a guard has
     * authenticated somebody it keeps returning them — a later request carrying a different
     * user's token would still be treated as the first. Real requests each boot a fresh
     * container; clearing the guards is what makes a test that switches users honest.
     */
    private function forgetAuth(): void
    {
        $this->app->get('auth')->forgetGuards();
    }

    // ─────────────────────── the permission catalogue ───────────────────────

    public function test_the_catalogue_lists_every_permission_grouped(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/permissions');

        // Assert
        $response->assertOk()
            ->assertJsonStructure(['data' => [['group', 'permissions' => [['name', 'label']]]]]);

        $names = collect($response->json('data'))->pluck('permissions')->flatten(1)->pluck('name');
        $this->assertEqualsCanonicalizing(PermissionName::values(), $names->all());
    }

    public function test_staff_cannot_read_the_permission_catalogue(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->staff());

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/permissions');

        // Assert
        $response->assertStatus(403);
    }

    // ─────────────────────── creating a role ───────────────────────

    public function test_an_administrator_creates_a_role_and_grants_it_permissions(): void
    {
        // Arrange — the exact flow: make a role, tick permissions from the catalogue.
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/roles', [
            'name' => 'warehouse',
            'permissions' => [
                PermissionName::ViewProducts->value,
                PermissionName::ViewCustomers->value,
            ],
        ]);

        // Assert
        $response->assertCreated()
            ->assertJson(['message' => 'تم إنشاء الدور بنجاح'])
            ->assertJsonPath('data.name', 'warehouse')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.can_be_deleted', true)
            ->assertJsonCount(2, 'data.permissions');

        $this->assertDatabaseHas('roles', ['name' => 'warehouse', 'guard_name' => 'web']);
    }

    public function test_a_new_role_grants_its_permissions_to_whoever_holds_it(): void
    {
        // Arrange — the whole point: create the role, give it to someone, they gain the access.
        $adminHeaders = $this->tokenFor($this->admin());
        $employee = $this->staff();

        $employeeHeaders = $this->tokenFor($employee);

        $roleId = $this->withHeaders($adminHeaders)->postJson('/api/v1/roles', [
            'name' => 'accountant-plus',
            'permissions' => [PermissionName::ViewUsers->value],
        ])->json('data.id');

        // The employee cannot list users yet.
        $this->forgetAuth();
        $this->withHeaders($employeeHeaders)->getJson('/api/v1/users')->assertStatus(403);

        // Act — give them the new role.
        $this->forgetAuth();
        $this->withHeaders($adminHeaders)->patchJson("/api/v1/users/{$employee->id}/roles", [
            'roles' => ['accountant-plus'],
        ])->assertOk();

        // Assert — the same token now gets through, purely because the role grants it.
        $this->forgetAuth();
        $this->withHeaders($employeeHeaders)->getJson('/api/v1/users')->assertOk();

        $this->assertNotNull($roleId);
    }

    public function test_a_role_cannot_be_granted_a_permission_the_code_does_not_know(): void
    {
        // Arrange — an invented permission would be a row that grants nothing.
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/roles', [
            'name' => 'wizard',
            'permissions' => ['summon.dragons'],
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('permissions.0');
        $this->assertDatabaseMissing('roles', ['name' => 'wizard']);
    }

    public function test_a_permission_the_seeder_has_not_written_yet_is_still_grantable(): void
    {
        // Arrange — the exact failure this guards against, and it has already happened once:
        // a release adds a case to PermissionName, the seeder is not re-run, and the row is
        // missing. StoreRoleRequest validates against the enum and lets the name through, so
        // without EnsurePermissionsExist Spatie throws PermissionDoesNotExist from inside
        // syncPermissions and the administrator gets a 500 for ticking a box we offered them.
        $headers = $this->tokenFor($this->admin());
        Permission::query()->where('name', PermissionName::DispatchOrders->value)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/roles', [
            'name' => 'dispatcher',
            'permissions' => [PermissionName::DispatchOrders->value],
        ]);

        // Assert — created, and the catalogue row was written on the way.
        $response->assertCreated()->assertJsonCount(1, 'data.permissions');
        $this->assertDatabaseHas('permissions', [
            'name' => PermissionName::DispatchOrders->value,
            'guard_name' => 'web',
        ]);
    }

    public function test_editing_a_role_also_writes_a_catalogue_row_the_seeder_missed(): void
    {
        // Arrange — the same gap on the other write path.
        $headers = $this->tokenFor($this->admin());
        $role = Role::findOrCreate('dispatcher', 'web');
        Permission::query()->where('name', PermissionName::DispatchOrders->value)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Act
        $response = $this->withHeaders($headers)->putJson('/api/v1/roles/'.$role->id, [
            'name' => 'dispatcher',
            'permissions' => [PermissionName::DispatchOrders->value],
        ]);

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data.permissions');
        $this->assertDatabaseHas('permissions', [
            'name' => PermissionName::DispatchOrders->value,
            'guard_name' => 'web',
        ]);
    }

    public function test_role_names_must_be_machine_readable_and_unique(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->admin());

        // Act
        $spaced = $this->withHeaders($headers)->postJson('/api/v1/roles', ['name' => 'head of sales']);
        $duplicate = $this->withHeaders($headers)->postJson('/api/v1/roles', ['name' => 'accountant']);

        // Assert
        $spaced->assertStatus(422)->assertJsonValidationErrors('name');
        $duplicate->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_staff_cannot_create_a_role(): void
    {
        // Arrange — otherwise anyone could mint themselves a role full of permissions.
        $headers = $this->tokenFor($this->staff());

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/roles', ['name' => 'sneaky']);

        // Assert
        $response->assertStatus(403);
        $this->assertDatabaseMissing('roles', ['name' => 'sneaky']);
    }

    // ─────────────────────── updating a role ───────────────────────

    public function test_updating_a_role_replaces_its_permission_set(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->admin());
        $roleId = $this->withHeaders($headers)->postJson('/api/v1/roles', [
            'name' => 'warehouse',
            'permissions' => [PermissionName::ViewProducts->value, PermissionName::ViewCustomers->value],
        ])->json('data.id');

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/roles/{$roleId}", [
            'name' => 'warehouse',
            'permissions' => [PermissionName::ManageProducts->value],
        ]);

        // Assert — replaced, not added to.
        $response->assertOk()->assertJsonCount(1, 'data.permissions');
        $this->assertSame(PermissionName::ManageProducts->value, $response->json('data.permissions.0.name'));
    }

    public function test_omitting_permissions_leaves_them_untouched(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->admin());
        $roleId = $this->withHeaders($headers)->postJson('/api/v1/roles', [
            'name' => 'warehouse',
            'permissions' => [PermissionName::ViewProducts->value],
        ])->json('data.id');

        // Act — rename only.
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/roles/{$roleId}", ['name' => 'stockroom']);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.name', 'stockroom')
            ->assertJsonCount(1, 'data.permissions');
    }

    public function test_an_empty_permissions_array_strips_them_all(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->admin());
        $roleId = $this->withHeaders($headers)->postJson('/api/v1/roles', [
            'name' => 'warehouse',
            'permissions' => [PermissionName::ViewProducts->value],
        ])->json('data.id');

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/roles/{$roleId}", ['name' => 'warehouse', 'permissions' => []]);

        // Assert
        $response->assertOk()->assertJsonCount(0, 'data.permissions');
    }

    // ─────────────────────── protecting the access system ───────────────────────

    public function test_the_admin_role_cannot_be_renamed(): void
    {
        // Arrange — the gate finds it by name, so renaming would revoke every administrator.
        $headers = $this->tokenFor($this->admin());
        $adminRole = Role::findByName(RoleName::Admin->value, 'web');

        // Act
        $response = $this->withHeaders($headers)
            ->putJson("/api/v1/roles/{$adminRole->id}", ['name' => 'superuser']);

        // Assert
        $response->assertStatus(422)->assertJson(['status' => false]);
        $this->assertDatabaseHas('roles', ['name' => 'admin']);
    }

    public function test_the_admin_role_cannot_be_given_permissions(): void
    {
        // Arrange — it already passes everything; a list would look meaningful and do nothing.
        $headers = $this->tokenFor($this->admin());
        $adminRole = Role::findByName(RoleName::Admin->value, 'web');

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/roles/{$adminRole->id}", [
            'name' => 'admin',
            'permissions' => [PermissionName::ViewUsers->value],
        ]);

        // Assert
        $response->assertStatus(422);
        $this->assertCount(0, $adminRole->fresh()->permissions);
    }

    public function test_a_system_role_cannot_be_deleted(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->admin());
        $staffRole = Role::findByName(RoleName::Staff->value, 'web');

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/roles/{$staffRole->id}");

        // Assert
        $response->assertStatus(422)->assertJson(['status' => false]);
        $this->assertDatabaseHas('roles', ['name' => 'staff']);
    }

    public function test_a_role_still_held_by_someone_cannot_be_deleted(): void
    {
        // Arrange — deleting it would strip their access as a side effect.
        $headers = $this->tokenFor($this->admin());
        $roleId = $this->withHeaders($headers)
            ->postJson('/api/v1/roles', ['name' => 'warehouse'])->json('data.id');

        $employee = $this->staff();
        $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/roles", ['roles' => ['warehouse']])
            ->assertOk();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/roles/{$roleId}");

        // Assert
        $response->assertStatus(422);
        $this->assertDatabaseHas('roles', ['id' => $roleId]);
    }

    public function test_an_unused_custom_role_can_be_deleted(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->admin());
        $roleId = $this->withHeaders($headers)
            ->postJson('/api/v1/roles', ['name' => 'warehouse'])->json('data.id');

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/roles/{$roleId}");

        // Assert — soft deleted, so it leaves the live set while the record of it having
        // existed survives.
        $response->assertOk()->assertJson(['status' => true]);
        $this->assertSoftDeleted('roles', ['id' => $roleId]);
        $this->assertNull(Role::query()->find($roleId));
    }

    // ─────────────────────── enforcement on real endpoints ───────────────────────

    public function test_the_seeded_staff_role_can_serve_customers_but_not_change_prices(): void
    {
        // Arrange — the starter set: customers yes, catalogue read-only.
        $headers = $this->tokenFor($this->staff());

        // Act
        $listCustomers = $this->withHeaders($headers)->getJson('/api/v1/customers');
        $listProducts = $this->withHeaders($headers)->getJson('/api/v1/products');
        $editPrices = $this->withHeaders($headers)->postJson('/api/v1/products', [
            'slug' => 'sneaky-bag', 'name' => 'كيس',
            'pricing_unit' => 'piece', 'pricing_mode' => 'tiered', 'min_order_quantity' => 1,
        ]);

        // Assert
        $listCustomers->assertOk();
        $listProducts->assertOk();
        $editPrices->assertStatus(403);
    }

    public function test_an_administrator_needs_no_permission_rows_to_do_any_of_it(): void
    {
        // Arrange
        $admin = $this->admin();
        $headers = $this->tokenFor($admin);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/products');

        // Assert
        $response->assertOk();
        $this->assertCount(0, $admin->getAllPermissions());
    }

    public function test_a_role_with_no_permissions_grants_nothing(): void
    {
        // Arrange — «محاسب» is seeded deliberately empty.
        $employee = User::factory()->create();
        $employee->syncRoles([RoleName::Accountant->value]);
        $headers = $this->tokenFor($employee);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/customers');

        // Assert
        $response->assertStatus(403);
    }
}
