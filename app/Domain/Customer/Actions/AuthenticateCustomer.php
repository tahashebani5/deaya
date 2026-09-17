<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Exceptions\CustomerAccountIsDeactivated;
use App\Domain\Customer\Exceptions\CustomerCredentialsAreWrong;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\AuthService;
use Illuminate\Support\Facades\Hash;

/**
 * Checks a phone number and a password, and answers with the customer or throws.
 *
 * **A null password is a refusal, not a way in.** Nearly every row in `customers` has one: those
 * people were entered by a clerk and have never opened the app. `Hash::check` against null is not
 * something to find out by accident, so the null is tested first and answered with the same
 * failure as a wrong password.
 *
 * **The order of the two failures is the security property.** Everything that means "you are not
 * who you say" collapses into one message, so the endpoint cannot be used to discover which phone
 * numbers belong to customers. Only once the password has checked out — at which point the caller
 * has proved the account is theirs — is a deactivated account told why it cannot come in.
 * {@see AuthService::login()} draws the same line for employees.
 *
 * Soft deleted customers never reach either branch: the model's global scope removes them from
 * this query, so a trashed row stops authenticating without a line of code saying so.
 */
final class AuthenticateCustomer
{
    public function __invoke(string $phone, string $password): Customer
    {
        $customer = Customer::query()->where('phone', $phone)->first();

        if ($customer === null || $customer->password === null || ! Hash::check($password, $customer->password)) {
            throw CustomerCredentialsAreWrong::make();
        }

        if (! $customer->is_active) {
            throw CustomerAccountIsDeactivated::make();
        }

        return $customer;
    }
}
