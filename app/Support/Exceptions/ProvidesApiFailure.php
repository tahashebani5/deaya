<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

/**
 * Implemented by any exception that already knows how it should look to an API client.
 *
 * The single handler registered in bootstrap/app.php renders *every* exception carrying this
 * interface, so a new failure type needs no wiring at all — declare it, throw it, done.
 */
interface ProvidesApiFailure
{
    /** The HTTP status this failure should produce. */
    public function httpStatus(): int;

    /** The message shown to the user, in Arabic. */
    public function userMessage(): string;

    /**
     * Field-level errors, in the same shape as a validation failure. Empty when the failure
     * does not belong to a particular field.
     *
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array;
}
