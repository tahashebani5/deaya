<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a staff account and gives it the roles it starts with.
 *
 * **Not `AuthService::register`.** That one exists to sign somebody *in* — it returns a token,
 * because a person who has just registered themselves is holding the phone. Here an
 * administrator is creating an account for a colleague who is somewhere else entirely, so
 * issuing a token would mint a live credential nobody asked for and hand it to the wrong person.
 * Same row, different transaction, different result.
 *
 * The employee code is not set here either: {@see User::booted()} stamps one on every insert,
 * because "an employee has a code" is an invariant of the model rather than a step in one way of
 * creating one.
 */
final class CreateEmployee
{
    /**
     * @param  list<string>  $roleNames
     */
    public function __invoke(
        string $name,
        string $email,
        string $phone,
        string $password,
        array $roleNames = [],
    ): User {
        return DB::transaction(function () use ($name, $email, $phone, $password, $roleNames): User {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                // The model casts `password` as 'hashed', so this is stored hashed.
                'password' => $password,
            ]);

            // Inside the transaction with the insert: an account that exists without the roles
            // it was created with is an account somebody has to notice and fix, and the window
            // where it is wrong is exactly the window where nobody is looking.
            $user->syncRoles($roleNames);

            return $user;
        })->load('roles');
    }
}
