<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\SetActivationRequest;
use App\Application\Api\V1\Requests\User\SetUserPasswordRequest;
use App\Application\Api\V1\Requests\User\SetUserSalaryRequest;
use App\Application\Api\V1\Requests\User\StoreUserRequest;
use App\Application\Api\V1\Requests\User\SyncUserRolesRequest;
use App\Application\Api\V1\Requests\User\UpdateUserRequest;
use App\Application\Api\V1\Resources\UserResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Identity\AccessService;
use App\Domain\Identity\Models\User;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Users
 *
 * Staff accounts, what they may do, and what they are paid.
 *
 * **Access is declared on the routes, and there are four different answers to «who may?»** —
 * which is why the details, the password and the salary are three endpoints rather than one
 * form:
 *
 * - reading needs `users.view`,
 * - correcting details, setting roles and stopping an account need `users.manage`,
 * - the salary needs `users.salary`, held by whoever agrees wages rather than whoever assigns
 *   roles,
 * - resetting somebody else's password is the **administrator's alone**, through a Gate that
 *   cannot be granted.
 *
 * See EMPLOYEE-DETAIL-DESIGN.md.
 */
class UserController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly AccessService $access) {}

    /**
     * List users
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $users = User::query()
            ->with('roles')
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.trim((string) $request->string('search')).'%';

                $query->where(function ($query) use ($term) {
                    $query->where('name', 'ilike', $term)
                        ->orWhere('email', 'ilike', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->orderBy('id')
            ->paginate($perPage);

        return $this->successWithPagination(UserResource::collection($users));
    }

    /**
     * Get one employee
     *
     * Their details and the roles they hold. `salary` is present only for a reader holding
     * `users.salary` — the key is **absent** for everybody else, which is a different fact from
     * a null (that one means «no wage recorded») and is why it is omitted rather than emptied.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        return $this->success(new UserResource($user->load('roles')));
    }

    /**
     * Create a staff account
     *
     * **Administrators only**, and not by permission: `users.create` is a gate ability rather
     * than a case in the permission catalogue, so it cannot be ticked onto a role. See
     * `AppServiceProvider::boot()` for why, and for the one edit that delegates it later.
     *
     * The account is usable immediately — the password given here is the one the employee signs
     * in with. No token is issued: the person holding the phone is not the person being created.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->access->createUser(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('phone')->toString(),
            $request->string('password')->toString(),
            $request->roleNames(),
        );

        return $this->created(new UserResource($user), 'تم إنشاء حساب الموظف بنجاح');
    }

    /**
     * Correct an employee's details
     *
     * Their name, email and phone. **Not their password and not their salary** — each of those
     * has an endpoint of its own because each has a different guard. A `password` sent here is
     * ignored rather than honoured.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->access->updateUser(
            $user,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('phone')->toString(),
        );

        return $this->success(new UserResource($updated), 'تم تحديث بيانات الموظف بنجاح');
    }

    /**
     * Reset an employee's password
     *
     * **Administrators only**, and not by permission — `users.password` is a Gate ability, so
     * it cannot be ticked onto a role. Whoever sets a colleague's password can sign in as them
     * and act under their name in the history.
     *
     * The current password is not asked for: the person typing is not the account holder. Every
     * session already open on the account is ended, because a reset that left them alive would
     * be no reset at all for the case it answers.
     */
    public function setPassword(SetUserPasswordRequest $request, User $user): JsonResponse
    {
        $updated = $this->access->setUserPassword($user, $request->string('password')->toString());

        return $this->success(new UserResource($updated), 'تم تغيير كلمة المرور بنجاح');
    }

    /**
     * Set an employee's monthly salary
     *
     * Needs `users.salary`. Send `null` to record that no wage has been agreed — which is a
     * real state, and different from a wage of zero.
     */
    public function setSalary(SetUserSalaryRequest $request, User $user): JsonResponse
    {
        $updated = $this->access->setUserSalary($user, $request->salary());

        return $this->success(new UserResource($updated), 'تم تحديث راتب الموظف بنجاح');
    }

    /**
     * Stop an account, or start it again
     *
     * Stopping refuses the next sign-in **and ends the sessions already open** — the column
     * alone would not throw out somebody holding a live token. Nothing is deleted: the employee
     * stays in the list, their name stays on everything they recorded, and starting the account
     * again is one tap.
     *
     * You cannot stop your own account: it would revoke the token making the request and lock
     * you out of the screen that could undo it.
     */
    public function setActivation(SetActivationRequest $request, User $user): JsonResponse
    {
        $updated = $this->access->setUserActive(
            $user,
            $request->boolean('is_active'),
            $request->user(),
        );

        return $this->success(
            new UserResource($updated),
            $updated->is_active ? 'تم تشغيل الحساب' : 'تم إيقاف الحساب',
        );
    }

    /**
     * Set a user's roles
     *
     * Replaces the whole set: send every role the user should end up with. An empty array
     * removes them all.
     */
    public function syncRoles(SyncUserRolesRequest $request, User $user): JsonResponse
    {
        $updated = $this->access->syncUserRoles($user, $request->roleNames());

        return $this->success(new UserResource($updated), 'تم تحديث أدوار المستخدم بنجاح');
    }

    /**
     * A user's history
     *
     * Every change to this account, newest first — including the changes someone else made to
     * it. To see what this user *did* rather than what was done to them, filter any history
     * endpoint with `causer_id`.
     *
     * Role assignments are not here: a user's roles live in a pivot table that fires no model
     * events. The change is recorded against the role instead — see `GET /roles/{role}/logs`.
     */
    public function logs(ActivityLogFilterRequest $request, User $user, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $user, $audit);
    }
}
