<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Creating a staff account — **the administrator's alone**.
 *
 * The gate is the point of this file. `users.create` is a Gate ability, not a case in
 * {@see PermissionName}, so it never appears on the roles screen and cannot be ticked onto a
 * role. The tests below prove both halves of that: an administrator passes, and somebody holding
 * *every* permission the catalogue offers still does not.
 *
 * Arrange - Act - Assert throughout.
 */
class CreateEmployeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->syncRoles([RoleName::Admin->value]);

        return $admin;
    }

    /**
     * @return array<string, string>
     */
    private function tokenFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'سالم المبروك',
            'email' => 'salem@printing.ly',
            'phone' => '0921234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    // ─────────────────────── who may ───────────────────────

    public function test_an_administrator_creates_a_staff_account(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/users', $this->payload());

        // Assert
        $response->assertCreated()
            ->assertJson(['message' => 'تم إنشاء حساب الموظف بنجاح'])
            ->assertJsonPath('data.name', 'سالم المبروك')
            ->assertJsonPath('data.phone', '0921234567')
            ->assertJsonPath('data.is_admin', false);

        $this->assertDatabaseHas('users', ['email' => 'salem@printing.ly']);
    }

    public function test_holding_every_permission_in_the_catalogue_still_does_not_let_you(): void
    {
        // Arrange — the whole reason `users.create` is a gate ability and not a permission. This
        // account has been granted literally everything the roles screen can offer; if creating
        // staff were a permission, it would be in that list.
        $role = Role::findOrCreate('super', 'web');
        $role->syncPermissions(PermissionName::values());

        $employee = User::factory()->create();
        $employee->syncRoles(['super']);
        $headers = $this->tokenFor($employee);

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/users', $this->payload());

        // Assert
        $response->assertStatus(403);
        $this->assertDatabaseMissing('users', ['email' => 'salem@printing.ly']);
    }

    public function test_managing_users_is_not_enough_either(): void
    {
        // Arrange — `users.manage` is what changing somebody's roles costs. Creating an account
        // is a different, larger thing, and sharing the permission would have made it the same.
        $role = Role::findOrCreate('hr', 'web');
        $role->syncPermissions([PermissionName::ViewUsers->value, PermissionName::ManageUsers->value]);

        $employee = User::factory()->create();
        $employee->syncRoles(['hr']);

        // Act
        $response = $this->withHeaders($this->tokenFor($employee))
            ->postJson('/api/v1/users', $this->payload());

        // Assert
        $response->assertStatus(403);
    }

    public function test_creating_a_staff_account_requires_authentication(): void
    {
        // Arrange — nothing; that is the point.

        // Act
        $response = $this->postJson('/api/v1/users', $this->payload());

        // Assert
        $response->assertStatus(401);
    }

    // ─────────────────────── what it produces ───────────────────────

    public function test_the_new_account_is_stamped_with_an_employee_code(): void
    {
        // Arrange — the code is the model's invariant, not a step in one way of creating a user,
        // so this path gets one without asking.
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/users', $this->payload());

        // Assert
        $response->assertCreated();
        $this->assertNotNull($response->json('data.employee_code'));
    }

    public function test_the_password_is_hashed_and_signs_the_new_employee_in(): void
    {
        // Arrange — the account has to be usable by the person it was made for, which is the
        // only way to know the password was stored as given.
        $headers = $this->tokenFor($this->admin());

        // Act
        $this->withHeaders($headers)->postJson('/api/v1/users', $this->payload())->assertCreated();
        $login = $this->postJson('/api/v1/auth/login', [
            'login' => 'salem@printing.ly',
            'password' => 'password123',
        ]);

        // Assert
        $login->assertOk()->assertJsonPath('data.user.name', 'سالم المبروك');
        $this->assertNotSame('password123', User::query()->where('email', 'salem@printing.ly')->value('password'));
        $this->assertTrue(Hash::check('password123', User::query()->where('email', 'salem@printing.ly')->value('password')));
    }

    public function test_no_token_is_issued_to_the_administrator_who_created_the_account(): void
    {
        // Arrange — registration hands back a token because the person registering is holding
        // the phone. Here they are not, so minting a live credential would hand it to the
        // wrong person.
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/users', $this->payload());

        // Assert
        $response->assertCreated();
        $this->assertNull($response->json('data.token'));
        $this->assertSame(0, User::query()->where('email', 'salem@printing.ly')->firstOrFail()->tokens()->count());
    }

    public function test_the_account_can_be_given_its_roles_as_it_is_created(): void
    {
        // Arrange — otherwise creating somebody useful is two steps, and the gap between them is
        // an account that can sign in and do nothing.
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson(
            '/api/v1/users',
            $this->payload(['roles' => [RoleName::Staff->value]]),
        );

        // Assert
        $response->assertCreated()->assertJsonPath('data.roles.0.name', RoleName::Staff->value);
        $this->assertTrue(
            User::query()->where('email', 'salem@printing.ly')->firstOrFail()->hasRole(RoleName::Staff->value),
        );
    }

    public function test_an_account_with_no_roles_is_allowed(): void
    {
        // Arrange — it can sign in and do nothing, which is a real and useful thing to create.
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/users', $this->payload());

        // Assert
        $response->assertCreated()->assertJsonCount(0, 'data.roles');
    }

    public function test_an_unknown_role_is_rejected_and_no_account_is_left_behind(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson(
            '/api/v1/users',
            $this->payload(['roles' => ['wizard']]),
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('roles.0');
        $this->assertDatabaseMissing('users', ['email' => 'salem@printing.ly']);
    }

    // ─────────────────────── what it refuses ───────────────────────

    public function test_an_email_or_phone_already_in_use_is_named_under_its_own_field(): void
    {
        // Arrange — either identifier signs in, so both are unique and both need saying where
        // the person can act on it.
        User::factory()->create(['email' => 'salem@printing.ly', 'phone' => '0921234567']);
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/users', $this->payload());

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_a_mistyped_confirmation_is_refused(): void
    {
        // Arrange — an administrator is typing a password for somebody who cannot see it, and a
        // typo would not surface until that person's first shift.
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson(
            '/api/v1/users',
            $this->payload(['password_confirmation' => 'something-else']),
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'salem@printing.ly']);
    }

    public function test_a_phone_that_is_not_a_libyan_mobile_is_refused(): void
    {
        // Arrange
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson(
            '/api/v1/users',
            $this->payload(['phone' => '1234']),
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_creating_an_administrator_is_allowed(): void
    {
        // Arrange — an administrator making another one is legitimate, and refusing it would
        // leave the business one lost password away from nobody being able to administer it.
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson(
            '/api/v1/users',
            $this->payload(['roles' => [RoleName::Admin->value]]),
        );

        // Assert
        $response->assertCreated()->assertJsonPath('data.is_admin', true);
    }
}
