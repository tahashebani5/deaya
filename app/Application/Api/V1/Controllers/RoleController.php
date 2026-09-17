<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Role\StoreRoleRequest;
use App\Application\Api\V1\Requests\Role\UpdateRoleRequest;
use App\Application\Api\V1\Resources\RoleResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Identity\AccessService;
use App\Domain\Identity\Models\Role;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * Roles
 *
 * A role is a named bundle of permissions. Staff get their access by holding one, so changing
 * what a job may do is a single edit to the role rather than a change to every person doing it.
 *
 * Create a role, tick permissions from `GET /permissions`, then give it to staff with
 * `PATCH /users/{user}/roles`.
 *
 * Two roles are protected: `admin` passes every check by rule — its permission list stays empty
 * and cannot be edited, and it cannot be renamed — and no role the code references can be
 * deleted. A role still held by staff cannot be deleted either, so nobody loses access as a
 * side effect.
 */
class RoleController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly AccessService $access) {}

    /**
     * List roles
     */
    public function index(): JsonResponse
    {
        return $this->success(RoleResource::collection($this->access->roles()));
    }

    /**
     * Create a role
     *
     * Permissions must come from the catalogue at `GET /permissions`.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->access->createRole(
            $request->string('name')->toString(),
            $request->permissionNames(),
        );

        return $this->created(new RoleResource($role), 'تم إنشاء الدور بنجاح');
    }

    /**
     * Get one role
     */
    public function show(Role $role): JsonResponse
    {
        return $this->success(new RoleResource($role->load('permissions')->loadCount('users')));
    }

    /**
     * Update a role
     *
     * Sending `permissions` replaces the whole set; omit it to leave the current one untouched.
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $updated = $this->access->updateRole(
            $role,
            $request->string('name')->toString(),
            $request->permissionNamesOrNull(),
        );

        return $this->success(new RoleResource($updated), 'تم تحديث الدور بنجاح');
    }

    /**
     * Delete a role
     *
     * Only roles the code does not reference, and that nobody currently holds.
     */
    public function destroy(Role $role): JsonResponse
    {
        $this->access->deleteRole($role);

        return $this->successMessage('تم حذف الدور بنجاح');
    }

    /**
     * A role's history
     *
     * Every change to the role, newest first — renames, and every time its permission set moved.
     *
     * A permission change appears as an `updated` entry whose `properties.permissions` names
     * what was `granted` and what was `revoked`. It is recorded by hand rather than by a model
     * event, because permissions live in a pivot table Eloquent has nothing to say about — and
     * this is the most consequential edit the API allows, so it is not left unsaid.
     */
    public function logs(ActivityLogFilterRequest $request, Role $role, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $role, $audit);
    }
}
