<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an account is told it may do.
 *
 * The app hides controls the signed-in person cannot use — a courtesy, never a boundary. The
 * boundary is `can:` on the route, and it stays there whatever the app shows. But the courtesy
 * needs an answer to work from, and this is where it comes from.
 *
 * The answer is computed by **asking the gate**, case by case, rather than by reading permission
 * rows. That is the only form that is true for both kinds of account: an administrator holds no
 * permission rows at all, because `Gate::before` grants that role everything.
 *
 * Arrange - Act - Assert throughout.
 */
class UserPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function bearerFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_an_administrator_is_told_they_may_do_everything(): void
    {
        // Arrange — and note what is *not* here: no permission rows. The admin role is granted
        // by a rule, so reading its pivot table would answer "nothing".
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->syncRoles([RoleName::Admin->value]);

        // Act
        $response = $this->getJson('/api/v1/auth/me', $this->bearerFor($admin));

        // Assert — every case in the catalogue, so a new permission cannot quietly go missing
        // from the one account allowed to use it.
        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            PermissionName::values(),
            $response->json('data.permissions'),
        );
    }

    public function test_a_staff_account_is_told_exactly_what_its_role_grants(): void
    {
        // Arrange — every permission in this system arrives through a role; none is granted
        // to a user directly.
        $this->seed(RoleSeeder::class);
        $staff = User::factory()->create();
        $staff->syncRoles([RoleName::Staff->value]);

        $granted = Role::findByName(RoleName::Staff->value, 'web')
            ->permissions
            ->pluck('name')
            ->all();

        // Act
        $response = $this->getJson('/api/v1/auth/me', $this->bearerFor($staff));

        // Assert — the whole point: role-granted permissions must be reported, and a reading
        // that only saw direct grants would answer with an empty array here.
        $response->assertOk();
        $this->assertNotEmpty($granted);
        $this->assertEqualsCanonicalizing($granted, $response->json('data.permissions'));
        $this->assertNotContains(
            PermissionName::ManageProducts->value,
            $response->json('data.permissions'),
        );
    }

    public function test_an_account_with_no_role_is_told_it_may_do_nothing(): void
    {
        // Arrange
        $newcomer = User::factory()->create();

        // Act
        $response = $this->getJson('/api/v1/auth/me', $this->bearerFor($newcomer));

        // Assert — present and empty. "The server did not say" and "you may do nothing" are
        // different answers, and the app treats them differently.
        $response->assertOk();
        $this->assertSame([], $response->json('data.permissions'));
    }

    public function test_signing_in_answers_with_the_permissions_too(): void
    {
        // Arrange — so the app has them before its first screen, without a second round trip.
        $this->seed(RoleSeeder::class);
        $staff = User::factory()->create(['phone' => '0911234567']);
        $staff->syncRoles([RoleName::Staff->value]);

        // Act
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => '0911234567',
            'password' => 'password',
        ]);

        // Assert
        $response->assertOk();
        $this->assertContains(
            PermissionName::ViewProducts->value,
            $response->json('data.user.permissions'),
        );
    }

    public function test_the_user_list_does_not_hand_out_everyone_permissions(): void
    {
        // Arrange — a list of colleagues has no business carrying each one's grants.
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->syncRoles([RoleName::Admin->value]);
        User::factory()->count(2)->create();

        // Act
        $response = $this->getJson('/api/v1/users', $this->bearerFor($admin));

        // Assert
        $response->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('permissions', $row);
        }
    }
}
