<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $known = RoleName::tryFrom($this->name);
        $isAdmin = $known === RoleName::Admin;

        return [
            'id' => $this->id,
            'name' => $this->name,
            // Falls back to the raw name for roles the business adds later, which the code
            // knows nothing about and does not need to.
            'label' => $known?->label() ?? $this->name,

            // An administrator's access comes from the gate, not from rows in a pivot table, so
            // its permission list is empty while its actual access is total. Saying so
            // explicitly stops that looking like a bug in a permissions screen.
            'grants_everything' => $isAdmin,

            // What the UI may offer: a system role cannot be renamed or deleted, and the
            // administrator's permissions cannot be edited.
            'is_system' => $known !== null,
            'can_be_renamed' => ! $isAdmin,
            'can_be_deleted' => $known === null,
            'can_edit_permissions' => ! $isAdmin,

            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions
                ->map(fn ($permission) => [
                    'name' => $permission->name,
                    'label' => PermissionName::tryFrom($permission->name)?->label() ?? $permission->name,
                ])
                ->values()),

            'users_count' => $this->whenCounted('users'),
        ];
    }
}
