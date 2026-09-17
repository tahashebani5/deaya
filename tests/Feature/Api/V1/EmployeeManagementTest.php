<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The employee's own screen: reading one, correcting their details, resetting their password,
 * setting their salary, and stopping the account of somebody who has left.
 *
 * **Five endpoints rather than one `PUT` with five fields, and the guards are the reason.**
 * `users.manage` corrects a phone number, `users.salary` sets a wage, and only an administrator
 * resets a password. A single endpoint would collapse three different answers to "who may?"
 * into the weakest of them. See EMPLOYEE-DETAIL-DESIGN.md §٢.
 *
 * Arrange - Act - Assert throughout.
 */
class EmployeeManagementTest extends TestCase
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
     * Somebody who holds exactly the permissions named and nothing else — never an
     * administrator, whose gate would pass every check and prove nothing.
     */
    private function staffWith(PermissionName ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $user->givePermissionTo(array_map(
            static fn (PermissionName $permission): string => $permission->value,
            $permissions,
        ));

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function tokenFor(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ─────────────────────── reading one employee ───────────────────────

    public function test_the_screen_reads_one_employee_with_their_roles(): void
    {
        // Arrange
        $employee = User::factory()->create(['name' => 'محمد عز الدين']);
        $employee->syncRoles([RoleName::Accountant->value]);

        $headers = $this->tokenFor($this->staffWith(PermissionName::ViewUsers));

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/users/{$employee->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.name', 'محمد عز الدين')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.roles.0.name', RoleName::Accountant->value);
    }

    public function test_a_reader_without_the_salary_permission_is_not_told_the_wage(): void
    {
        // Arrange
        $employee = User::factory()->create(['salary' => '2500.00']);
        $headers = $this->tokenFor($this->staffWith(PermissionName::ViewUsers));

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/users/{$employee->id}");

        // Assert — the key is absent, not null and not zero: «you may not know» is a different
        // fact from «no salary is recorded», and the app draws a section off this.
        $response->assertOk();
        $this->assertArrayNotHasKey('salary', $response->json('data'));
    }

    public function test_a_reader_holding_the_salary_permission_is_told_it(): void
    {
        // Arrange
        $employee = User::factory()->create(['salary' => '2500.00']);
        $headers = $this->tokenFor(
            $this->staffWith(PermissionName::ViewUsers, PermissionName::ManageUserSalaries),
        );

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/users/{$employee->id}");

        // Assert
        $response->assertOk()->assertJsonPath('data.salary', '2500.00');
    }

    public function test_an_employee_with_no_recorded_salary_carries_a_null_rather_than_a_zero(): void
    {
        // Arrange — «لم يُحدَّد» is a real answer about a real employee, and a nought would be
        // a wage of nothing.
        $employee = User::factory()->create(['salary' => null]);
        $headers = $this->tokenFor(
            $this->staffWith(PermissionName::ViewUsers, PermissionName::ManageUserSalaries),
        );

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/users/{$employee->id}");

        // Assert
        $response->assertOk()->assertJsonPath('data.salary', null);
    }

    // ─────────────────────── correcting their details ───────────────────────

    public function test_the_details_can_be_corrected(): void
    {
        // Arrange
        $employee = User::factory()->create(['phone' => '0910000000']);
        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUsers,
        ));

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/users/{$employee->id}", [
            'name' => 'محمد عز الدين',
            'email' => 'mohamed@printing.ly',
            'phone' => '0944909851',
        ]);

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.name', 'محمد عز الدين')
            ->assertJsonPath('data.phone', '0944909851');

        $this->assertDatabaseHas('users', ['id' => $employee->id, 'phone' => '0944909851']);
    }

    public function test_saving_a_form_that_did_not_change_the_email_is_not_a_duplicate(): void
    {
        // Arrange — the uniqueness rule has to ignore the row being edited, or correcting a
        // phone number would fail on an email nobody touched.
        $employee = User::factory()->create(['email' => 'mohamed@printing.ly']);
        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUsers,
        ));

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/users/{$employee->id}", [
            'name' => $employee->name,
            'email' => 'mohamed@printing.ly',
            'phone' => '0944909852',
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.email', 'mohamed@printing.ly');
    }

    public function test_somebody_elses_phone_number_is_still_refused(): void
    {
        // Arrange
        User::factory()->create(['phone' => '0944909853']);
        $employee = User::factory()->create();
        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUsers,
        ));

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/users/{$employee->id}", [
            'name' => $employee->name,
            'email' => $employee->email,
            'phone' => '0944909853',
        ]);

        // Assert — either identifier signs in, so both stay unique.
        $response->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_correcting_the_details_never_touches_the_password(): void
    {
        // Arrange — a `password` in this payload must be ignored rather than honoured: this
        // endpoint is guarded by `users.manage`, and resetting a password is the
        // administrator's alone.
        $employee = User::factory()->create(['password' => 'original-password']);
        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUsers,
        ));

        // Act
        $this->withHeaders($headers)->putJson("/api/v1/users/{$employee->id}", [
            'name' => $employee->name,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'password' => 'smuggled-password',
        ])->assertOk();

        // Assert
        $this->assertTrue(Hash::check('original-password', $employee->fresh()->password));
    }

    public function test_correcting_the_details_needs_the_permission_to_manage_users(): void
    {
        // Arrange
        $employee = User::factory()->create();
        $headers = $this->tokenFor($this->staffWith(PermissionName::ViewUsers));

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/users/{$employee->id}", [
            'name' => 'اسم جديد',
            'email' => $employee->email,
            'phone' => $employee->phone,
        ]);

        // Assert
        $response->assertForbidden();
    }

    // ─────────────────────── resetting a password ───────────────────────

    public function test_an_administrator_resets_a_forgotten_password(): void
    {
        // Arrange
        $employee = User::factory()->create(['password' => 'forgotten-one']);
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/password", [
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ]);

        // Assert
        $response->assertOk();
        $this->assertTrue(Hash::check('brand-new-password', $employee->fresh()->password));
    }

    public function test_a_reset_password_ends_the_sessions_that_were_open_on_the_old_one(): void
    {
        // Arrange — a phone already signed in as this employee.
        $employee = User::factory()->create();
        $employee->createToken('their-phone');

        $headers = $this->tokenFor($this->admin());

        // Act
        $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/password", [
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])->assertOk();

        // Assert — a reset that left the old session alive would be no reset at all for the
        // case it exists to answer: a device somebody else is holding.
        $this->assertSame(0, $employee->tokens()->count());
    }

    public function test_a_mistyped_confirmation_is_refused(): void
    {
        // Arrange — asked for even though an administrator is typing it for somebody else, and
        // *because* they are: the colleague cannot see what was typed and nobody finds out
        // until their next shift.
        $employee = User::factory()->create(['password' => 'original-password']);
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/password", [
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-passward',
            ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('original-password', $employee->fresh()->password));
    }

    public function test_every_permission_in_the_catalogue_still_does_not_reset_a_password(): void
    {
        // Arrange — the gate is the point: `users.password` is not a PermissionName case, so it
        // never appears on the roles screen and cannot be ticked onto a role.
        $employee = User::factory()->create();
        $everything = $this->staffWith(...PermissionName::cases());
        $headers = $this->tokenFor($everything);

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/password", [
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ]);

        // Assert
        $response->assertForbidden();
    }

    // ─────────────────────── the salary ───────────────────────

    public function test_the_salary_is_set_by_somebody_holding_that_permission(): void
    {
        // Arrange
        $employee = User::factory()->create(['salary' => null]);
        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUserSalaries,
        ));

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/salary", ['salary' => '2500.5']);

        // Assert
        $response->assertOk()->assertJsonPath('data.salary', '2500.50');
        $this->assertDatabaseHas('users', ['id' => $employee->id, 'salary' => '2500.50']);
    }

    public function test_the_salary_can_be_cleared_back_to_unrecorded(): void
    {
        // Arrange
        $employee = User::factory()->create(['salary' => '2500.00']);
        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUserSalaries,
        ));

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/salary", ['salary' => null]);

        // Assert — «لم يُحدَّد» has to be reachable, or a number typed by mistake is permanent.
        $response->assertOk()->assertJsonPath('data.salary', null);
    }

    public function test_a_negative_salary_is_refused(): void
    {
        // Arrange
        $employee = User::factory()->create();
        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUserSalaries,
        ));

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/salary", ['salary' => '-100']);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('salary');
    }

    public function test_managing_users_is_not_enough_to_set_a_wage(): void
    {
        // Arrange — the whole reason `users.salary` is its own permission: assigning somebody a
        // role is not the same job as knowing what everyone is paid.
        $employee = User::factory()->create();
        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUsers,
        ));

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/salary", ['salary' => '9000']);

        // Assert
        $response->assertForbidden();
    }

    // ─────────────────────── stopping an account ───────────────────────

    public function test_a_stopped_account_can_no_longer_sign_in(): void
    {
        // Arrange
        $employee = User::factory()->create(['password' => 'still-correct']);
        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUsers,
        ));

        $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/activation", ['is_active' => false])
            ->assertOk();

        // Act
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $employee->email,
            'password' => 'still-correct',
        ]);

        // Assert — the password is right and the account still answers; what refuses is the
        // account being stopped, and it says so rather than pretending the password is wrong.
        $response->assertStatus(422);
    }

    public function test_stopping_an_account_ends_the_sessions_already_open_on_it(): void
    {
        // Arrange — the column alone would not throw out somebody already signed in.
        $employee = User::factory()->create();
        $employee->createToken('their-phone');

        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUsers,
        ));

        // Act
        $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/activation", ['is_active' => false])
            ->assertOk();

        // Assert
        $this->assertSame(0, $employee->tokens()->count());
    }

    public function test_starting_the_account_again_lets_them_back_in(): void
    {
        // Arrange
        $employee = User::factory()->create(['password' => 'still-correct', 'is_active' => false]);
        $headers = $this->tokenFor($this->staffWith(
            PermissionName::ViewUsers,
            PermissionName::ManageUsers,
        ));

        $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/activation", ['is_active' => true])
            ->assertOk();

        // Act
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $employee->email,
            'password' => 'still-correct',
        ]);

        // Assert — reversible, which is the whole reason this is a column and not a delete.
        $response->assertOk();
    }

    public function test_nobody_can_stop_their_own_account(): void
    {
        // Arrange
        $manager = $this->staffWith(PermissionName::ViewUsers, PermissionName::ManageUsers);
        $headers = $this->tokenFor($manager);

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$manager->id}/activation", ['is_active' => false]);

        // Assert — one tap from locking yourself out of the screen that could undo it.
        $response->assertStatus(422);
        $this->assertTrue($manager->fresh()->is_active);
    }

    public function test_stopping_an_account_needs_the_permission_to_manage_users(): void
    {
        // Arrange
        $employee = User::factory()->create();
        $headers = $this->tokenFor($this->staffWith(PermissionName::ViewUsers));

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/users/{$employee->id}/activation", ['is_active' => false]);

        // Assert
        $response->assertForbidden();
    }

    public function test_a_newly_created_account_answers_that_it_is_active(): void
    {
        // Arrange — the column's default lives in the database, and a model that has just been
        // inserted has not read it back. Without a default on the model too, the create
        // response would carry `is_active: null` and the app would draw a «موقوف» badge on an
        // account nobody stopped.
        $headers = $this->tokenFor($this->admin());

        // Act
        $response = $this->withHeaders($headers)->postJson('/api/v1/users', [
            'name' => 'سالم المبروك',
            'email' => 'salem@printing.ly',
            'phone' => '0921234567',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.is_active', true);
    }

    public function test_the_list_says_which_accounts_are_stopped(): void
    {
        // Arrange — a stopped employee stays in the list rather than vanishing, because the
        // screen that puts them back is this one.
        $stopped = User::factory()->create(['is_active' => false]);
        $headers = $this->tokenFor($this->staffWith(PermissionName::ViewUsers));

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/users');

        // Assert
        $states = collect($response->json('data'))->pluck('is_active', 'id');

        $response->assertOk();
        $this->assertFalse($states[$stopped->id]);
    }
}
