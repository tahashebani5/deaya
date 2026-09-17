<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Role;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\Role;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Written out in full rather than merged onto the parent's rules: Scramble reads this method
 * statically to build the request body and cannot follow `array_merge(parent::rules(), …)`.
 */
class UpdateRoleRequest extends StoreRoleRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60', 'regex:/^[a-z0-9_-]+$/', $this->nameUniqueAmongOtherRoles()],
            // Omit to leave the current permission set untouched; send an empty array to strip
            // every permission from the role.
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(PermissionName::values())],
        ];
    }

    private function nameUniqueAmongOtherRoles(): Unique
    {
        /** @var Role $role */
        $role = $this->route('role');

        return Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role->getKey())->withoutTrashed();
    }

    /**
     * Distinguishes "not sent" from "sent empty" — the first keeps the current permissions, the
     * second clears them.
     *
     * @return list<string>|null
     */
    public function permissionNamesOrNull(): ?array
    {
        if (! $this->has('permissions')) {
            return null;
        }

        return array_values(array_unique((array) ($this->validated('permissions') ?? [])));
    }
}
