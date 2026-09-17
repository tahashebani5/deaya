<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A role still held by staff cannot be deleted.
 *
 * Deleting it would strip those people's access without anyone deciding that should happen —
 * and the pivot rows would go quietly, leaving no trace of what was lost. Move them off the
 * role first, so the change is a decision rather than a side effect.
 */
final class RoleInUseCannotBeDeleted extends DomainException
{
    public static function make(string $name, int $userCount): self
    {
        return new self(
            "لا يمكن حذف الدور «{$name}» لأنه مُسند إلى {$userCount} مستخدم — انقلهم إلى دور آخر أولاً"
        );
    }
}
