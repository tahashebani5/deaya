<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Base class for a business rule being broken.
 *
 * The rule this codebase follows: **throw, never catch.** Domain code states what went wrong
 * by throwing one of these and then stops caring; it never wraps calls in try/catch to
 * translate errors, because there is exactly one place that turns a failure into a response —
 * the handler in bootstrap/app.php. That keeps error shaping in one file instead of scattered
 * across every service, and makes it impossible for one caller to swallow a failure another
 * caller reports.
 *
 * Subclasses carry their own status and Arabic message, so the meaning of a failure lives next
 * to the rule that produced it rather than in the controller that happened to trigger it.
 *
 * These are *expected* outcomes — a customer typing the wrong password is normal traffic, not
 * a fault — so the class implements ShouldntReport and stays out of the error log. A genuine
 * bug is a different animal: it throws some other exception, is logged, and becomes a 500.
 */
abstract class DomainException extends RuntimeException implements ProvidesApiFailure, ShouldntReport
{
    public function httpStatus(): int
    {
        return 422;
    }

    public function userMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function fieldErrors(): array
    {
        return [];
    }
}
