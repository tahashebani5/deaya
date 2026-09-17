<?php

declare(strict_types=1);

namespace App\Domain\Identity\DTOs;

use App\Domain\Identity\Models\User;

/**
 * The outcome of a successful authentication: who logged in, and the token they
 * must send back. Typed so the service→controller boundary carries no arrays.
 */
final readonly class AuthResult
{
    public function __construct(
        public User $user,
        public string $token,
    ) {}
}
