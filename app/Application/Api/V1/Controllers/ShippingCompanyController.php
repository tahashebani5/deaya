<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Delivery\StoreShippingCompanyRequest;
use App\Application\Api\V1\Requests\Delivery\UpdateShippingCompanyRequest;
use App\Application\Api\V1\Resources\ShippingCompanyResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Delivery\DeliveryService;
use App\Domain\Delivery\DTOs\ShippingCompanyData;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Delivery\Queries\ShippingCompanyFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shipping companies
 *
 * Who carries our parcels. An order dispatched for delivery names one of these, and keeps a
 * snapshot of the name beside the key — so removing a company here never rewrites what an old
 * order said carried it.
 *
 * Reading needs `shipping_companies.view`; changing the list needs `shipping_companies.manage`.
 */
class ShippingCompanyController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly DeliveryService $delivery) {}

    /**
     * List shipping companies
     *
     * The ones still in use first, then by name. `search` matches the name or the phone;
     * `is_active=1` narrows it to the ones a dispatch may still be handed to.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = ShippingCompanyFilters::fromArray($request->only(['search', 'is_active']));
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            ShippingCompanyResource::collection($this->delivery->paginateShippingCompanies($filters, $perPage)),
        );
    }

    /**
     * Create a shipping company
     */
    public function store(StoreShippingCompanyRequest $request): JsonResponse
    {
        $company = $this->delivery->createShippingCompany(
            ShippingCompanyData::fromArray($request->validated()),
        );

        return $this->created(new ShippingCompanyResource($company), 'تم إضافة شركة التوصيل بنجاح');
    }

    /**
     * Get one shipping company
     */
    public function show(ShippingCompany $shippingCompany): JsonResponse
    {
        return $this->success(new ShippingCompanyResource($shippingCompany));
    }

    /**
     * Update a shipping company
     *
     * Switching `is_active` off is how a carrier is retired: it stops being offered on new
     * dispatches and every order that already names it is untouched.
     */
    public function update(
        UpdateShippingCompanyRequest $request,
        ShippingCompany $shippingCompany,
    ): JsonResponse {
        $updated = $this->delivery->updateShippingCompany(
            $shippingCompany,
            ShippingCompanyData::fromArray($request->validated()),
        );

        return $this->success(new ShippingCompanyResource($updated), 'تم تحديث شركة التوصيل بنجاح');
    }

    /**
     * Delete a shipping company
     *
     * For the row that should not have existed. The orders it carried keep naming it.
     */
    public function destroy(ShippingCompany $shippingCompany): JsonResponse
    {
        $this->delivery->deleteShippingCompany($shippingCompany);

        return $this->successMessage('تم حذف شركة التوصيل بنجاح');
    }

    /**
     * A shipping company's history
     */
    public function logs(
        ActivityLogFilterRequest $request,
        ShippingCompany $shippingCompany,
        AuditService $audit,
    ): JsonResponse {
        return $this->auditTrailResponse($request, $shippingCompany, $audit);
    }
}
