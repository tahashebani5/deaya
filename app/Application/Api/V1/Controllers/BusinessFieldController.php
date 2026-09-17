<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Customer\StoreBusinessFieldRequest;
use App\Application\Api\V1\Requests\Customer\UpdateBusinessFieldRequest;
use App\Application\Api\V1\Requests\SetActivationRequest;
use App\Application\Api\V1\Resources\BusinessFieldResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Customer\CustomerService;
use App\Domain\Customer\DTOs\BusinessFieldData;
use App\Domain\Customer\Models\BusinessField;
use App\Domain\Customer\Queries\BusinessFieldFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business fields
 *
 * مجالات العمل — the trade a customer's shop is in: شحن, بيع ملابس, مطاعم ومقاهي … A short list
 * the business curates, picked from when a shop is recorded, and kept so that «من نبيع له؟» can
 * one day be answered from records rather than from memory.
 *
 * Reading needs `business_fields.view`, which every role is granted: anyone opening a customer
 * form needs the list to pick from. Changing the list needs `business_fields.manage`. Both are
 * declared on the routes, so the guard on an endpoint is visible without opening this file.
 */
class BusinessFieldController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly CustomerService $customers) {}

    /**
     * List business fields
     *
     * In the business's own order, then by name. `search` matches the name; `is_active=1` is
     * what a picker asks for, while the management screen leaves it off and sees both. Each row
     * carries `shops_count` — how many shops are recorded in that trade.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = BusinessFieldFilters::fromArray($request->only(['search', 'is_active']));
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            BusinessFieldResource::collection($this->customers->paginateBusinessFields($filters, $perPage)),
        );
    }

    /**
     * Create a business field
     */
    public function store(StoreBusinessFieldRequest $request): JsonResponse
    {
        $field = $this->customers->createBusinessField(BusinessFieldData::fromArray($request->validated()));

        return $this->created(new BusinessFieldResource($field), 'تم إضافة مجال العمل بنجاح');
    }

    /**
     * Get one business field
     */
    public function show(BusinessField $businessField): JsonResponse
    {
        return $this->success(new BusinessFieldResource($businessField->loadCount('shops')));
    }

    /**
     * Update a business field
     *
     * Renaming applies everywhere at once, including to the shops already recorded under it:
     * this is a label, not a snapshot of what a shop sold on some particular day.
     */
    public function update(UpdateBusinessFieldRequest $request, BusinessField $businessField): JsonResponse
    {
        $updated = $this->customers->updateBusinessField(
            $businessField,
            BusinessFieldData::fromArray($request->validated()),
        );

        return $this->success(new BusinessFieldResource($updated), 'تم تحديث مجال العمل بنجاح');
    }

    /**
     * Activate or deactivate a business field
     *
     * The ordinary way to retire one. It disappears from the pickers; every shop recorded under
     * it keeps saying so.
     */
    public function setActivation(SetActivationRequest $request, BusinessField $businessField): JsonResponse
    {
        $isActive = $request->boolean('is_active');
        $updated = $this->customers->setBusinessFieldActive($businessField, $isActive);

        return $this->success(
            new BusinessFieldResource($updated),
            $isActive ? 'تم تفعيل مجال العمل' : 'تم إيقاف مجال العمل',
        );
    }

    /**
     * Delete a business field
     *
     * Only for the row that should never have existed. A field any shop is recorded under is
     * refused with 422 — deactivate it instead.
     */
    public function destroy(BusinessField $businessField): JsonResponse
    {
        $this->customers->deleteBusinessField($businessField);

        return $this->successMessage('تم حذف مجال العمل بنجاح');
    }

    /**
     * A business field's history
     *
     * Who added it, who renamed it, who stopped offering it — newest first. Filter with
     * `event`, `causer_id`, `from` and `to`.
     */
    public function logs(
        ActivityLogFilterRequest $request,
        BusinessField $businessField,
        AuditService $audit,
    ): JsonResponse {
        return $this->auditTrailResponse($request, $businessField, $audit);
    }
}
