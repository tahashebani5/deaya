<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exceptions;

use App\Domain\Notification\Enums\FcmSendResult;
use RuntimeException;

/**
 * Firebase refused or could not be reached, for a reason that is worth retrying.
 *
 * **Not a `DomainException`.** Every other failure in this application is a business rule
 * explaining itself to a user through the envelope; this one has no user. It happens inside a
 * queued job, nobody is waiting on a response, and the right answer is for the queue to try
 * again with backoff and eventually give up into `failed_jobs` where an operator can see it. So
 * it is an ordinary exception that *should* be reported, unlike the `ShouldntReport` family.
 *
 * A dead device token is deliberately **not** this: that is an expected, permanent outcome
 * handled by deleting the row — see {@see FcmSendResult}.
 */
final class FcmRequestFailed extends RuntimeException
{
    public static function make(int $status, string $body): self
    {
        return new self("FCM rejected the request with status {$status}: {$body}");
    }
}
