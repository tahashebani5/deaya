<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Identity\Actions\UpdateRole;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A named bundle of permissions.
 *
 * Spatie's model, subclassed for the two things every model in this application does: it soft
 * deletes and it keeps a history. `config/permission.php` points `models.role` here, which is
 * what makes the package hand back this class everywhere — no caller changes.
 *
 * **Access control is the thing an audit trail exists for.** "Who gave the accountant permission
 * to edit prices, and when" is not a question a permissions screen can answer, because a
 * permissions screen only ever shows the present. This is the answer.
 *
 * The trail does *not* cover which permissions a role holds: those live in `role_has_permissions`,
 * a pivot table with no model and no events. {@see UpdateRole}
 * records that change explicitly, because a sync of that table is the single most consequential
 * edit this API allows.
 */
class Role extends SpatieRole implements HasAuditTrail
{
    use Auditable, SoftDeletes;

    /**
     * Spatie guards nothing (`$guarded = []`) and declares no `$fillable`, so the trait's usual
     * "log every attribute" default would still work — but naming the three columns is worth the
     * line here. A role's row is `id`, `name`, `guard_name`; anything the package adds to that
     * table in a future version is its business, not our history.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'guard_name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName($this->getMorphClass())
            ->setDescriptionForEvent(fn (string $event): string => $this->auditDescription($event));
    }
}
