<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\Actions\CreateEmployee;
use App\Domain\Identity\Actions\CreateRole;
use App\Domain\Identity\Actions\DeleteRole;
use App\Domain\Identity\Actions\UpdateEmployee;
use App\Domain\Identity\Actions\UpdateRole;
use App\Domain\Identity\Exceptions\CannotStopOwnAccount;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Identity module's front door for everything about roles and access.
 *
 * Other modules ask here rather than reaching for Spatie's models directly, so the day roles
 * are stored or cached differently, only this class changes.
 */
class AccessService
{
    public function __construct(
        private readonly CreateRole $createRole,
        private readonly UpdateRole $updateRole,
        private readonly DeleteRole $deleteRole,
        private readonly CreateEmployee $createEmployee,
        private readonly UpdateEmployee $updateEmployee,
    ) {}

    /**
     * Creates a staff account with the roles it starts with.
     *
     * @param  list<string>  $roleNames
     */
    public function createUser(
        string $name,
        string $email,
        string $phone,
        string $password,
        array $roleNames = [],
    ): User {
        return ($this->createEmployee)($name, $email, $phone, $password, $roleNames);
    }

    /**
     * Corrects an employee's details. Never their password and never their salary — those have
     * endpoints and guards of their own.
     */
    public function updateUser(User $user, string $name, string $email, string $phone): User
    {
        return ($this->updateEmployee)($user, $name, $email, $phone);
    }

    /**
     * Sets a new password for somebody who is not the one asking.
     *
     * **And ends every session already open on the account.** A reset that left them alive
     * would be no reset at all for the case it exists to answer — a device in somebody else's
     * hands — and the new password would guard nothing but the next sign-in.
     */
    public function setUserPassword(User $user, string $password): User
    {
        // The model casts `password` as 'hashed', so this is stored hashed.
        $user->update(['password' => $password]);
        $user->tokens()->delete();

        return $user->load('roles');
    }

    /**
     * What this employee is paid a month. Null is «لم يُحدَّد», which is a state an account can
     * genuinely be in and has to be reachable again once a number has been typed by mistake.
     */
    public function setUserSalary(User $user, ?string $salary): User
    {
        $user->update(['salary' => $salary]);

        return $user->load('roles');
    }

    /**
     * Stops an account, or starts it again.
     *
     * **Stopping revokes the tokens too**, because the column alone would not throw out
     * somebody already signed in: their phone holds a bearer token that keeps working until it
     * is deleted. Starting the account again issues nothing — they sign in as normal.
     *
     * @throws CannotStopOwnAccount when the account is the one making the request.
     */
    public function setUserActive(User $user, bool $isActive, ?User $actor = null): User
    {
        if (! $isActive && $actor !== null && $actor->is($user)) {
            throw CannotStopOwnAccount::make();
        }

        $user->update(['is_active' => $isActive]);

        if (! $isActive) {
            $user->tokens()->delete();
        }

        return $user->load('roles');
    }

    /**
     * @return Collection<int, Role>
     */
    public function roles(): Collection
    {
        $roles = Role::query()->with('permissions')->orderBy('id')->get();

        // loadCount on the fetched models, not withCount on the query. Spatie resolves the user
        // model from the role's own guard_name, and withCount builds the relation against a bare
        // instance that has no attributes yet — so the guard is null and it blows up.
        $roles->loadCount('users');

        return $roles;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function createRole(string $name, array $permissions = []): Role
    {
        return ($this->createRole)($name, $permissions);
    }

    /**
     * @param  list<string>|null  $permissions
     */
    public function updateRole(Role $role, string $name, ?array $permissions = null): Role
    {
        return ($this->updateRole)($role, $name, $permissions);
    }

    public function deleteRole(Role $role): void
    {
        ($this->deleteRole)($role);
    }

    /**
     * @param  list<string>  $roleNames
     */
    public function syncUserRoles(User $user, array $roleNames): User
    {
        $user->syncRoles($roleNames);

        return $user->load('roles');
    }
}
