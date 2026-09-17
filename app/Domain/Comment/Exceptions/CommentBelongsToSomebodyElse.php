<?php

declare(strict_types=1);

namespace App\Domain\Comment\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Somebody tried to rewrite or remove a note they did not write.
 *
 * The buttons are absent in the app for a note that is not the reader's — `can_edit` and
 * `can_delete` travel with every row — and this is the half that matters: a hidden button is a
 * suggestion, a refused request is a rule.
 */
final class CommentBelongsToSomebodyElse extends DomainException
{
    public static function make(): self
    {
        return new self('هذه الملاحظة كتبها موظف آخر، ولا تملك صلاحية تعديل ملاحظات الآخرين');
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
