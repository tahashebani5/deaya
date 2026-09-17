<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use App\Domain\Identity\DTOs\AuthResult;
use App\Domain\Identity\Exceptions\AccountDeactivated;
use App\Domain\Identity\Exceptions\InvalidCredentials;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * All authentication business logic. Controllers only translate HTTP to these calls.
 */
class AuthService
{
    private const DEFAULT_DEVICE_NAME = 'api';

    /**
     * Create the account and hand back a ready-to-use token, so the client does not
     * have to follow registration with a second login round-trip.
     */
    public function register(string $name, string $email, string $phone, string $password, ?string $deviceName = null): AuthResult
    {
        $user = DB::transaction(fn (): User => User::create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            // The model casts `password` as 'hashed', so this is stored hashed.
            'password' => $password,
        ]));

        return new AuthResult($user, $this->issueToken($user, $deviceName));
    }

    /**
     * Authenticate by email *or* phone.
     *
     * @throws InvalidCredentials when the identifier and password do not match an account.
     */
    public function login(string $login, string $password, ?string $deviceName = null): AuthResult
    {
        $user = User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        // One failure for both "no such user" and "wrong password" — telling them apart
        // would let an attacker enumerate registered accounts. Thrown rather than returned:
        // the service's job is to state the failure, and the boundary decides how it looks.
        if (! $user || ! Hash::check($password, $user->password)) {
            throw InvalidCredentials::make();
        }

        // **After the password check, never before.** Answering «هذا الحساب موقوف» to a wrong
        // password would turn this endpoint into a way of discovering which accounts exist —
        // the very thing the single failure above is written to prevent. Past that line the
        // caller has already proved they are the account holder, so they may be told why.
        if (! $user->is_active) {
            throw AccountDeactivated::make();
        }

        return new AuthResult($user, $this->issueToken($user, $deviceName));
    }

    /**
     * Revoke only the token used for the current request, leaving other devices signed in.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        // Under cookie/SPA auth this is a TransientToken, which has nothing to revoke.
        // Only a token that is actually persisted can be deleted. Checking for Model
        // rather than PersonalAccessToken keeps this working with a custom token model.
        if ($token instanceof Model) {
            $token->delete();
        }
    }

    /**
     * Revoke every token this user holds — the "sign out everywhere" action.
     */
    public function logoutFromAllDevices(User $user): void
    {
        $user->tokens()->delete();
    }

    private function issueToken(User $user, ?string $deviceName): string
    {
        return $user->createToken($deviceName ?: self::DEFAULT_DEVICE_NAME)->plainTextToken;
    }
}
