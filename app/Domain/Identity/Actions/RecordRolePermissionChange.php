<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Identity\Models\Role;

/**
 * Writes the one audit entry no model event can produce.
 *
 * Every other change in this application is a column moving on a row, and {@see
 * \App\Domain\Audit\Concerns\Auditable} catches all of those without being asked. A role's
 * permissions are not a column: they are rows in `role_has_permissions`, a pivot table with no
 * model, no events and nothing to observe. Sync it and Eloquent has nothing to say.
 *
 * That gap sits over the most consequential edit the API allows — the moment somebody gains the
 * ability to change prices or read customer records. So it is filled by hand, in the two places
 * that can move it, and this class exists so those two places record it identically.
 *
 * Written as an `updated` entry on the role rather than an event of its own: a client filtering
 * the trail should not have to know that permissions are stored differently from names.
 */
final class RecordRolePermissionChange
{
    /**
     * Records nothing when the set did not actually change — re-sending the same permissions is
     * not an event, exactly as `logOnlyDirty` treats re-sending the same name.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function __invoke(Role $role, array $before, array $after): void
    {
        sort($before);
        sort($after);

        if ($before === $after) {
            return;
        }

        activity()
            ->useLog($role->getMorphClass())
            ->on($role)
            ->event(AuditEvent::Updated->value)
            ->withProperties([
                'permissions' => [
                    'old' => $before,
                    'attributes' => $after,
                    'granted' => array_values(array_diff($after, $before)),
                    'revoked' => array_values(array_diff($before, $after)),
                ],
            ])
            ->log(AuditEvent::Updated->sentence(AuditSubject::Role->label()));
    }
}
