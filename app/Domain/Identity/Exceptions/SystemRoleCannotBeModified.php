<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * A role the code depends on cannot be renamed or deleted.
 *
 * `admin` is referenced by name in the authorization gate, so renaming it would silently
 * revoke every administrator's access — the kind of change that looks harmless in a form and
 * locks everyone out in production.
 */
final class SystemRoleCannotBeModified extends DomainException
{
    public static function renamed(string $name): self
    {
        return new self("لا يمكن إعادة تسمية الدور «{$name}» لأنه دور أساسي في النظام");
    }

    public static function deleted(string $name): self
    {
        return new self("لا يمكن حذف الدور «{$name}» لأنه دور أساسي في النظام");
    }

    public static function permissionsChanged(string $name): self
    {
        return new self("صلاحيات الدور «{$name}» غير قابلة للتعديل — هذا الدور يملك كل الصلاحيات دائماً");
    }
}
