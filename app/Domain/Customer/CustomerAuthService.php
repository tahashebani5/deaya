<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use App\Domain\Customer\Actions\AuthenticateCustomer;
use App\Domain\Customer\Actions\RegisterCustomerAccount;
use App\Domain\Customer\DTOs\CustomerAuthResult;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\AuthService;
use Illuminate\Database\Eloquent\Model;

/**
 * The customer app's authentication, and the only door to it.
 *
 * Its own service rather than a corner of {@see CustomerService}: that class is the door the
 * *staff* modules knock on — Orders asks it for a customer, Reporting counts through it — and
 * signing somebody in is a different audience answering a different question. `Identity` already
 * splits the same way, with `AuthService` beside `AccessService`.
 *
 * Deliberately not built on {@see AuthService}, which would have meant
 * Customer depending on Identity for its own front door. The two are the same shape and stay
 * separate on purpose: employees log in by email *or* phone and may be granted roles, customers
 * log in by phone alone and are never granted anything.
 */
class CustomerAuthService
{
    /**
     * What a token is called when the app does not say which device it is on.
     */
    private const DEFAULT_DEVICE_NAME = 'client';

    public function __construct(
        private readonly RegisterCustomerAccount $register,
        private readonly AuthenticateCustomer $authenticate,
    ) {}

    /**
     * Create the account and hand back a ready-to-use token, so the app does not have to follow
     * registration with a second login round-trip.
     */
    public function register(string $name, string $phone, string $password, ?string $deviceName = null): CustomerAuthResult
    {
        $customer = ($this->register)($name, $phone, $password);

        return new CustomerAuthResult($customer, $this->issueToken($customer, $deviceName));
    }

    public function login(string $phone, string $password, ?string $deviceName = null): CustomerAuthResult
    {
        $customer = ($this->authenticate)($phone, $password);

        return new CustomerAuthResult($customer, $this->issueToken($customer, $deviceName));
    }

    /**
     * Revoke only the token that made this request, leaving the customer's other devices signed
     * in. The `instanceof Model` check is what {@see AuthService::logout()}
     * does and for the same reason: a cookie-authenticated request carries a `TransientToken`,
     * which has nothing to delete.
     */
    public function logout(Customer $customer): void
    {
        $token = $customer->currentAccessToken();

        if ($token instanceof Model) {
            $token->delete();
        }
    }

    /** «تسجيل الخروج من كل الأجهزة». */
    public function logoutFromAllDevices(Customer $customer): void
    {
        $customer->tokens()->delete();
    }

    private function issueToken(Customer $customer, ?string $deviceName): string
    {
        return $customer->createToken($deviceName ?: self::DEFAULT_DEVICE_NAME)->plainTextToken;
    }
}
