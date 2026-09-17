<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\CustomerData;
use App\Domain\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * A customer signs himself up from the app.
 *
 * **Built on {@see CreateCustomer} rather than beside it**, so a customer who registers is the
 * same kind of row as one a clerk typed in: same code from the same sequence, same shop sync,
 * same audit entry. A second insert here would be a second definition of what a customer is, and
 * the two would drift the first time either changed.
 *
 * The password is set afterwards rather than passed through {@see CustomerData}: that DTO
 * describes a customer as the staff screens know one, and no staff endpoint may ever carry a
 * password field into this domain. `password` is absent from the model's fillable list for the
 * same reason, so it is assigned here as a property and hashed by the model's cast.
 */
final class RegisterCustomerAccount
{
    public function __construct(private readonly CreateCustomer $createCustomer) {}

    public function __invoke(string $name, string $phone, string $password): Customer
    {
        return DB::transaction(function () use ($name, $phone, $password): Customer {
            $customer = ($this->createCustomer)(new CustomerData(name: $name, phone: $phone));

            $customer->password = $password;
            $customer->save();

            return $customer;
        });
    }
}
