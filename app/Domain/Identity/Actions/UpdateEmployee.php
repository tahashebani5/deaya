<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;

/**
 * Corrects who an employee is: their name, their email, their number.
 *
 * **Three fields and no fourth.** The password and the salary are set through endpoints of
 * their own because each answers a different «who may?» — see EMPLOYEE-DETAIL-DESIGN.md §٢ —
 * and this action naming its columns explicitly is what makes a smuggled `password` in the
 * payload impossible rather than merely unvalidated.
 */
final class UpdateEmployee
{
    public function __invoke(User $user, string $name, string $email, string $phone): User
    {
        $user->update([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]);

        return $user->load('roles');
    }
}
