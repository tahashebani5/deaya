<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Controller;
use App\Domain\Identity\Enums\PermissionName;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * Permissions
 *
 * The catalogue of everything the system can check for. Read-only by design: a permission is
 * real only because code checks for it, so inventing one at runtime would create a row that
 * grants nothing. Build roles from this list.
 */
class PermissionController extends Controller
{
    use ResponseTrait;

    /**
     * List available permissions
     *
     * Grouped, ready to render as sections of checkboxes on a role screen.
     */
    public function index(): JsonResponse
    {
        $grouped = collect(PermissionName::cases())
            ->groupBy(fn (PermissionName $permission) => $permission->group())
            ->map(fn ($permissions, $group) => [
                'group' => $group,
                'permissions' => $permissions
                    ->map(fn (PermissionName $permission) => [
                        'name' => $permission->value,
                        'label' => $permission->label(),
                    ])
                    ->values(),
            ])
            ->values();

        return $this->success($grouped);
    }
}
