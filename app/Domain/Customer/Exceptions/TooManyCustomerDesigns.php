<?php

declare(strict_types=1);

namespace App\Domain\Customer\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A customer already holds as many designs as the system keeps for one.
 *
 * A business rule, so it lives in the action rather than the FormRequest: a console import or a
 * future bulk upload has to obey it too, and a rule that only exists in an HTTP validator is one
 * every other entry point skips.
 *
 * It is also the only real bound on storage here, because design files are never deleted — the
 * row is hidden and the object stays so that an old order can still show what it printed.
 */
final class TooManyCustomerDesigns extends DomainException
{
    public function __construct(private readonly int $limit)
    {
        parent::__construct("لا يمكن حفظ أكثر من {$limit} تصميماً للعميل الواحد");
    }

    public function fieldErrors(): array
    {
        return ['file' => [$this->getMessage()]];
    }
}
