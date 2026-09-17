<?php

use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        /*
         * **`provider` here is a security control, not boilerplate — do not remove it.**
         *
         * Sanctum registers this guard itself, in `SanctumServiceProvider::register()`, with
         * `'provider' => null`. And `Guard::hasValidProvider()` returns `true` whenever the
         * provider is null — meaning the default `auth:sanctum` accepts a token issued to *any*
         * tokenable model. That was harmless while `users` was the only one; the moment
         * `Customer` started issuing tokens it stopped being harmless, because a customer's token
         * would have satisfied every staff route.
         *
         * Permissions would not have saved it: `GET /v1/home/summary` is deliberately the one
         * endpoint in routes/api.php with no `can:` beside it, so a customer holding a valid
         * staff-guard token would have been served the workshop's home screen.
         *
         * Naming `users` here is what makes the two apps two populations. `CustomerAuthTest`
         * watches this line from both directions.
         */
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],

        /*
         * The customer app's guard. Same driver and the same token table — a customer's token is
         * an ordinary personal access token — and the provider below is the entire separation.
         */
        'customer' => [
            'driver' => 'sanctum',
            'provider' => 'customers',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        // The customer app's population. Separate from `users` because that table is
        // employee-shaped — a required unique email and a burned employee code on every row —
        // and neither fits somebody who just wants to reorder bags. See
        // Docs/customer-app/CUSTOMER-APP-DESIGN.md §٢.
        'customers' => [
            'driver' => 'eloquent',
            'model' => Customer::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
