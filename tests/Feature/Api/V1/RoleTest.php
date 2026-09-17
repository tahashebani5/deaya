<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Roles and access.
 *
 * The rules under test: an administrator passes everything without holding any permission, an
 * employee starts with none, and only an administrator may hand out roles.
 *
 * Arrange - Act - Assert throughout.
 */
class RoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleName::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
    }

    private function userWithRole(RoleName $role): User
    {
        $user = User::factory()->create();
        $user->syncRoles([$role->value]);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function tokenFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ─────────────────────── the administrator's blanket access ───────────────────────

    public function test_an_administrator_passes_an_ability_nobody_was_granted(): void
    {
        // Arrange — the ability is never defined and no permission exists for it.
        $admin = $this->userWithRole(RoleName::Admin);

        // Act
        $allowed = Gate::forUser($admin)->allows('some ability invented on the spot');

        // Assert
        $this->assertTrue($allowed);
    }

    public function test_an_employee_is_denied_an_ability_nobody_was_granted(): void
    {
        // Arrange
        $employee = $this->userWithRole(RoleName::Staff);

        // Act
        $allowed = Gate::forUser($employee)->allows('some ability invented on the spot');

        // Assert
        $this->assertFalse($allowed);
    }

    public function test_the_administrator_holds_no_permission_rows_yet_still_passes(): void
    {
        // Arrange — access comes from the gate, not from a pivot table.
        $admin = $this->userWithRole(RoleName::Admin);

        // Act & Assert
        $this->assertCount(0, $admin->getAllPermissions());
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Gate::forUser($admin)->allows('manage users'));
    }

    public function test_an_employee_passes_once_their_role_is_granted_the_permission(): void
    {
        // Arrange — this is the whole point of roles: grant once, everyone in the job gains it.
        $employee = $this->userWithRole(RoleName::Accountant);
        $this->assertFalse(Gate::forUser($employee)->allows('manage users'));

        // Act
        $permission = Permission::findOrCreate('manage users', 'web');
        Role::findByName(RoleName::Accountant->value, 'web')->givePermissionTo($permission);

        // Assert
        $this->assertTrue(Gate::forUser($employee->fresh())->allows('manage users'));
    }

    // ─────────────────────── who may manage access ───────────────────────

    public function test_an_administrator_can_list_roles(): void
    {
        // Arrange
        $admin = $this->userWithRole(RoleName::Admin);

        // Act
        $response = $this->withHeaders($this->tokenFor($admin))->getJson('/api/v1/roles');

        // Assert
        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'label', 'grants_everything', 'permissions']]]);

        $names = array_column($response->json('data'), 'name');
        $this->assertContains('admin', $names);
        $this->assertContains('accountant', $names);
    }

    public function test_an_employee_cannot_list_roles(): void
    {
        // Arrange
        $employee = $this->userWithRole(RoleName::Staff);

        // Act
        $response = $this->withHeaders($this->tokenFor($employee))->getJson('/api/v1/roles');

        // Assert
        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'message' => 'ليس لديك صلاحية لتنفيذ هذا الإجراء',
                'data' => null,
            ]);
    }

    public function test_an_employee_cannot_list_users(): void
    {
        // Arrange
        $employee = $this->userWithRole(RoleName::Staff);

        // Act
        $response = $this->withHeaders($this->tokenFor($employee))->getJson('/api/v1/users');

        // Assert
        $response->assertStatus(403);
    }

    public function test_an_administrator_can_list_users_with_their_roles(): void
    {
        // Arrange
        $admin = $this->userWithRole(RoleName::Admin);
        $this->userWithRole(RoleName::Staff);

        // Act
        $response = $this->withHeaders($this->tokenFor($admin))->getJson('/api/v1/users');

        // Assert
        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'email', 'phone', 'roles', 'is_admin']], 'meta'])
            ->assertJsonPath('meta.total', 2);
    }

    // ─────────────────────── assigning a role ───────────────────────

    public function test_an_administrator_can_give_an_employee_a_job_role(): void
    {
        // Arrange
        $admin = $this->userWithRole(RoleName::Admin);
        $employee = $this->userWithRole(RoleName::Staff);

        // Act
        $response = $this->withHeaders($this->tokenFor($admin))->patchJson(
            "/api/v1/users/{$employee->id}/roles",
            ['roles' => [RoleName::Staff->value, RoleName::Accountant->value]],
        );

        // Assert
        $response->assertOk()->assertJson(['message' => 'تم تحديث أدوار المستخدم بنجاح']);
        $this->assertEqualsCanonicalizing(
            ['staff', 'accountant'],
            $employee->fresh()->getRoleNames()->all(),
        );
    }

    public function test_syncing_replaces_the_whole_set_rather_than_adding(): void
    {
        // Arrange
        $admin = $this->userWithRole(RoleName::Admin);
        $employee = $this->userWithRole(RoleName::Staff);

        // Act
        $this->withHeaders($this->tokenFor($admin))->patchJson(
            "/api/v1/users/{$employee->id}/roles",
            ['roles' => [RoleName::Accountant->value]],
        )->assertOk();

        // Assert — staff is gone, not kept alongside.
        $this->assertSame(['accountant'], $employee->fresh()->getRoleNames()->all());
    }

    public function test_an_empty_list_strips_every_role(): void
    {
        // Arrange
        $admin = $this->userWithRole(RoleName::Admin);
        $employee = $this->userWithRole(RoleName::Staff);

        // Act
        $response = $this->withHeaders($this->tokenFor($admin))
            ->patchJson("/api/v1/users/{$employee->id}/roles", ['roles' => []]);

        // Assert
        $response->assertOk();
        $this->assertCount(0, $employee->fresh()->getRoleNames());
    }

    public function test_an_employee_cannot_promote_themselves_to_administrator(): void
    {
        // Arrange — the escalation this endpoint exists to prevent.
        $employee = $this->userWithRole(RoleName::Staff);

        // Act
        $response = $this->withHeaders($this->tokenFor($employee))->patchJson(
            "/api/v1/users/{$employee->id}/roles",
            ['roles' => [RoleName::Admin->value]],
        );

        // Assert
        $response->assertStatus(403);
        $this->assertFalse($employee->fresh()->isAdmin());
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        // Arrange
        $admin = $this->userWithRole(RoleName::Admin);
        $employee = $this->userWithRole(RoleName::Staff);

        // Act
        $response = $this->withHeaders($this->tokenFor($admin))->patchJson(
            "/api/v1/users/{$employee->id}/roles",
            ['roles' => ['wizard']],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('roles.0');
        $this->assertSame(['staff'], $employee->fresh()->getRoleNames()->all());
    }

    public function test_the_roles_key_is_required(): void
    {
        // Arrange
        $admin = $this->userWithRole(RoleName::Admin);
        $employee = $this->userWithRole(RoleName::Staff);

        // Act
        $response = $this->withHeaders($this->tokenFor($admin))
            ->patchJson("/api/v1/users/{$employee->id}/roles", []);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('roles');
    }

    public function test_managing_access_requires_authentication(): void
    {
        // Arrange
        $employee = $this->userWithRole(RoleName::Staff);

        // Act
        $roles = $this->getJson('/api/v1/roles');
        $sync = $this->patchJson("/api/v1/users/{$employee->id}/roles", ['roles' => []]);

        // Assert
        $roles->assertStatus(401);
        $sync->assertStatus(401);
    }

    // ─────────────────────── roles on the authenticated user ───────────────────────

    public function test_a_user_can_see_their_own_roles(): void
    {
        // Arrange
        $employee = $this->userWithRole(RoleName::Accountant);

        // Act
        $response = $this->withHeaders($this->tokenFor($employee))->getJson('/api/v1/auth/me');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.is_admin', false)
            ->assertJsonPath('data.roles.0.name', 'accountant')
            ->assertJsonPath('data.roles.0.label', 'محاسب');
    }

    public function test_an_administrator_sees_the_admin_flag_on_their_own_account(): void
    {
        // Arrange
        $admin = $this->userWithRole(RoleName::Admin);

        // Act
        $response = $this->withHeaders($this->tokenFor($admin))->getJson('/api/v1/auth/me');

        // Assert
        $response->assertOk()->assertJsonPath('data.is_admin', true);
    }
}
