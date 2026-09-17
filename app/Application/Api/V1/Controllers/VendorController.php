<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\SetActivationRequest;
use App\Application\Api\V1\Requests\Vendor\StoreVendorRequest;
use App\Application\Api\V1\Requests\Vendor\UpdateVendorRequest;
use App\Application\Api\V1\Resources\VendorResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Vendor\DTOs\VendorData;
use App\Domain\Vendor\Models\Vendor;
use App\Domain\Vendor\Queries\VendorFilters;
use App\Domain\Vendor\VendorService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vendors
 *
 * The suppliers the business buys stock from. Reading needs `vendors.view`; changing the list
 * needs `vendors.manage`. Both are declared on the routes.
 *
 * No destroy route: a vendor is deactivated, never deleted, so every shipment already on record
 * keeps pointing at a real row.
 */
class VendorController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly VendorService $vendors) {}

    /**
     * List vendors
     *
     * Newest first. `search` matches the name, the contact person, or the phone number.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = VendorFilters::fromArray($request->only(['search', 'is_active']));
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            VendorResource::collection($this->vendors->paginate($filters, $perPage)),
        );
    }

    /**
     * Create a vendor
     */
    public function store(StoreVendorRequest $request): JsonResponse
    {
        $vendor = $this->vendors->create(VendorData::fromArray($request->validated()));

        return $this->created(new VendorResource($vendor), 'تم إضافة المورد بنجاح');
    }

    /**
     * Get one vendor
     */
    public function show(Vendor $vendor): JsonResponse
    {
        return $this->success(new VendorResource($vendor));
    }

    /**
     * Update a vendor
     *
     * The vendor's details are replaced by what is sent, so leaving a field out clears it —
     * except `is_active`, which is left untouched when omitted. Use the activation endpoint to
     * change it.
     */
    public function update(UpdateVendorRequest $request, Vendor $vendor): JsonResponse
    {
        $updated = $this->vendors->update($vendor, VendorData::fromArray($request->validated()));

        return $this->success(new VendorResource($updated), 'تم تحديث بيانات المورد بنجاح');
    }

    /**
     * Activate or deactivate a vendor
     *
     * Vendors are never deleted — deactivating keeps their purchase history intact.
     */
    public function setActivation(SetActivationRequest $request, Vendor $vendor): JsonResponse
    {
        $updated = $this->vendors->setActive($vendor, $request->boolean('is_active'));

        return $this->success(
            new VendorResource($updated),
            $updated->is_active ? 'تم تنشيط المورد' : 'تم إلغاء تنشيط المورد',
        );
    }

    /**
     * A vendor's history
     *
     * Every change to the vendor itself, newest first — who made it and what it was before.
     *
     * **Shipments are not here, deliberately.** They grow for as long as the business keeps
     * buying from this vendor, and `GET /stock-arrivals?vendor_id=` is the reader built for them.
     *
     * Filter with `event`, `causer_id`, `from` and `to`.
     */
    public function logs(ActivityLogFilterRequest $request, Vendor $vendor, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $vendor, $audit);
    }
}
