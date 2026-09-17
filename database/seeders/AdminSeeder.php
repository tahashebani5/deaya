<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * The two accounts the system starts with: an administrator, and one ordinary employee.
 *
 * Both exist so the difference between them can actually be tried — logging in as the employee
 * is the only honest way to see that the administrator's blanket access is real and that the
 * employee's is not.
 *
 * Idempotent — safe to re-run without duplicating either account.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@printing.ly'],
            [
                'name' => 'المدير',
                'phone' => '0910000000',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        // syncRoles rather than assignRole: re-running must not stack duplicates, and the
        // administrator's roles should be exactly this.
        $admin->syncRoles([RoleName::Admin->value]);

        $employee = User::query()->updateOrCreate(
            ['email' => 'staff@printing.ly'],
            [
                'name' => 'موظف',
                'phone' => '0911000001',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        // Deliberately holds no permissions yet. Attach them to a job role — «محاسب» and the
        // like — and give the employee that role when the business decides what each job may do.
        $employee->syncRoles([RoleName::Staff->value]);
    }
}
