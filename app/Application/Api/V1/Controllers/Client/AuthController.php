<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers\Client;

use App\Application\Api\V1\Requests\Client\Auth\LoginCustomerRequest;
use App\Application\Api\V1\Requests\Client\Auth\RegisterCustomerRequest;
use App\Application\Api\V1\Resources\Client\CustomerAccountResource;
use App\Application\Controller;
use App\Domain\Customer\CustomerAuthService;
use App\Domain\Customer\Models\Customer;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer authentication
 *
 * Register, sign in, and manage the current session's token — for the customer app, not the
 * staff one. A token issued here satisfies the `customer` guard and nothing else.
 */
class AuthController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly CustomerAuthService $auth) {}

    /**
     * Register a new customer account
     *
     * Phone number and password — no email. Returns a token, so no second login call is needed.
     */
    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $result = $this->auth->register(
            name: $request->string('name')->toString(),
            phone: $request->string('phone')->toString(),
            password: $request->string('password')->toString(),
            deviceName: $request->string('device_name')->toString() ?: null,
        );

        return $this->created([
            'customer' => new CustomerAccountResource($result->customer),
            'token' => $result->token,
        ], 'تم إنشاء الحساب بنجاح');
    }

    /**
     * Log in
     *
     * By phone number and password. Unlike the staff endpoint there is no email alternative —
     * a customer account has no email at all.
     */
    public function login(LoginCustomerRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            phone: $request->string('phone')->toString(),
            password: $request->string('password')->toString(),
            deviceName: $request->string('device_name')->toString() ?: null,
        );

        return $this->success([
            'customer' => new CustomerAccountResource($result->customer),
            'token' => $result->token,
        ], 'تم تسجيل الدخول بنجاح');
    }

    /**
     * My account
     *
     * **No id in the path, and that is the security.** The customer is resolved from the token,
     * so there is nothing for a caller to change in order to read somebody else's account. Every
     * endpoint in the customer API is shaped this way — the same rule `investor-portal` follows.
     */
    public function me(Request $request): JsonResponse
    {
        // **Only `me` loads the shop.** Register and login answer with an account that has just
        // been created or just been proved, and neither moment is one where «حسابي» is on
        // screen; loading three relations to fill a card nobody is looking at is a query the
        // sign-in screen pays for. `whenLoaded` in the resource means those two responses simply
        // omit the key.
        $customer = $this->customer($request)->load(Customer::SHOP_RELATIONS);

        return $this->success(new CustomerAccountResource($customer));
    }

    /**
     * Log out of this device
     *
     * Revokes only the token that made this request; other devices stay signed in.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($this->customer($request));

        return $this->successMessage('تم تسجيل الخروج');
    }

    /**
     * Log out everywhere
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->auth->logoutFromAllDevices($this->customer($request));

        return $this->successMessage('تم تسجيل الخروج من كل الأجهزة');
    }

    /**
     * The signed-in customer, narrowed from the guard's contract to the model.
     *
     * `$request->user()` is typed as `Authenticatable` and every method here needs a `Customer`.
     * Asserting it once keeps the narrowing in one place — and the route group's `auth:customer`
     * middleware is what makes it true, so a failure here means a route was registered outside
     * that group.
     */
    private function customer(Request $request): Customer
    {
        $customer = $request->user();

        assert($customer instanceof Customer);

        return $customer;
    }
}
