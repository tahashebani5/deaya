<?php

declare(strict_types=1);

namespace App\Domain\Carrier\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * The carrier could not be reached, or answered with something that was not a response.
 *
 * A transport failure: a timeout, a refused connection, a 500. Distinct from
 * {@see NawrisRejectedRequest}, which is the carrier working correctly and saying no — the two
 * want different handling, because this one is worth retrying and that one is not.
 *
 * **Raised through `Http::throw()`'s callback rather than a `catch`**, which is what keeps this
 * integration inside the no-try/catch rule that `ErrorHandlingTest` enforces.
 */
final class NawrisRequestFailed extends DomainException
{
    public static function make(string $operation, ?int $status = null): self
    {
        $suffix = $status !== null ? " (HTTP {$status})" : '';

        return new self("تعذّر الاتصال بشركة نورس أثناء {$operation}{$suffix}");
    }

    /** Infrastructure, not a business rule — so a 502 rather than the 422 a DomainException gives. */
    public function httpStatus(): int
    {
        return 502;
    }
}
