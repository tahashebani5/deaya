<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Investor\Models\Investor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,

            // What the app puts on the employee's card, next to their name.
            'employee_code' => $this->employee_code,

            // Whether this account can still sign in. Stopped accounts stay in the list — the
            // screen that puts them back is the one that lists them.
            'is_active' => $this->is_active,

            // What this employee is paid a month, for a reader allowed to know.
            //
            // **Absent rather than null for everybody else**, and the two are different facts:
            // `null` means «no wage has been agreed», which the salary sheet shows as «لم
            // يُحدَّد», while a missing key means «you may not be told» and hides the section
            // outright. `users.salary` is its own permission precisely so that managing staff
            // and knowing their wages can be granted apart — see EMPLOYEE-DETAIL-DESIGN.md §١.
            'salary' => $this->when(
                $request->user()?->can(PermissionName::ManageUserSalaries->value) === true,
                fn () => $this->salary,
            ),

            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'name' => $role->name,
                'label' => RoleName::tryFrom($role->name)?->label() ?? $role->name,
            ])->values()),

            // The client should not have to know that "admin" is special — it asks the server.
            'is_admin' => $this->when($this->relationLoaded('roles'), fn () => $this->isAdmin()),

            // **Whether this account belongs to an investor** — read off the `investors.user_id`
            // link, which is a fact about a row rather than the name of a role. The app routes
            // on it, so renaming the «مستثمر» role cannot strand somebody on a screen meant for
            // employees.
            'is_investor' => Investor::query()
                ->where('user_id', $this->id)
                ->where('is_active', true)
                ->exists(),

            // What this account may do, as the gate answers it — not as its pivot table reads.
            //
            // Asked case by case rather than plucked from the permission rows, because an
            // administrator holds **no rows at all**: RoleSeeder says so on purpose, since
            // Gate::before grants that role everything. Only asking the gate is true for both
            // kinds of account, and it leaves "admin is special" in AppServiceProvider — the one
            // file that already says it.
            //
            // Expanded here rather than sent as rows for the client to OR with `is_admin`: the
            // day a second blanket rule lands, a client-side OR is a stale partial copy of
            // Gate::before, and the bug surfaces as missing buttons three screens from its cause.
            //
            // Guarded on both relations so the key is simply absent from GET /users — a list of
            // colleagues has no business carrying everyone's grants.
            'permissions' => $this->when(
                $this->relationLoaded('roles') && $this->relationLoaded('permissions'),
                fn () => collect(PermissionName::cases())
                    ->filter(fn (PermissionName $permission) => $this->can($permission->value))
                    ->map(fn (PermissionName $permission) => $permission->value)
                    ->values()
                    ->all(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
