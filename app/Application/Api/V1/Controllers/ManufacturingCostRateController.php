<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Order\StoreManufacturingCostRateRequest;
use App\Application\Api\V1\Requests\Order\UpdateManufacturingCostRateRequest;
use App\Application\Api\V1\Requests\SetActivationRequest;
use App\Application\Api\V1\Resources\ManufacturingCostRateResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Order\DTOs\ManufacturingCostRateData;
use App\Domain\Order\Models\ManufacturingCostRate;
use App\Domain\Order\OrderService;
use App\Domain\Order\Queries\ManufacturingCostRateFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manufacturing cost rates
 *
 * What a unit of production standard-costs at — labour, machine runtime, overhead — applied
 * automatically the moment an order enters printing. A short list the business curates from a
 * screen, the same shape business fields already is, rather than an enum a deployment would be
 * needed to change.
 *
 * Reading needs `manufacturing_cost_rates.view`; changing the table needs
 * `manufacturing_cost_rates.manage`.
 */
class ManufacturingCostRateController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly OrderService $orders) {}

    /**
     * List manufacturing cost rates
     *
     * The most specific rate first — a size's own, then a product's, then the default — then by
     * cost type. Filter with `product_id`, `product_variant_id`, `cost_type` and `is_active`.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = ManufacturingCostRateFilters::fromArray(
            $request->only(['product_id', 'product_variant_id', 'cost_type', 'is_active']),
        );
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            ManufacturingCostRateResource::collection($this->orders->paginateManufacturingCostRates($filters, $perPage)),
        );
    }

    /**
     * Create a manufacturing cost rate
     */
    public function store(StoreManufacturingCostRateRequest $request): JsonResponse
    {
        $rate = $this->orders->createManufacturingCostRate(
            ManufacturingCostRateData::fromArray($request->validated()),
        );

        return $this->created(new ManufacturingCostRateResource($rate->load(['product', 'productVariant'])), 'تم إضافة المعدل بنجاح');
    }

    /**
     * Get one manufacturing cost rate
     */
    public function show(ManufacturingCostRate $manufacturingCostRate): JsonResponse
    {
        return $this->success(new ManufacturingCostRateResource($manufacturingCostRate->load(['product', 'productVariant'])));
    }

    /**
     * Update a manufacturing cost rate
     *
     * Only affects orders entering printing after this runs — an order already costed keeps the
     * rate its own production-cost entries snapshotted.
     */
    public function update(
        UpdateManufacturingCostRateRequest $request,
        ManufacturingCostRate $manufacturingCostRate,
    ): JsonResponse {
        $updated = $this->orders->updateManufacturingCostRate(
            $manufacturingCostRate,
            ManufacturingCostRateData::fromArray($request->validated()),
        );

        return $this->success(new ManufacturingCostRateResource($updated->load(['product', 'productVariant'])), 'تم تحديث المعدل بنجاح');
    }

    /**
     * Activate or deactivate a manufacturing cost rate
     */
    public function setActivation(SetActivationRequest $request, ManufacturingCostRate $manufacturingCostRate): JsonResponse
    {
        $isActive = $request->boolean('is_active');
        $updated = $this->orders->setManufacturingCostRateActive($manufacturingCostRate, $isActive);

        return $this->success(
            new ManufacturingCostRateResource($updated->load(['product', 'productVariant'])),
            $isActive ? 'تم تفعيل المعدل' : 'تم إيقاف المعدل',
        );
    }

    /**
     * Delete a manufacturing cost rate
     *
     * For the row that should never have existed. Nothing points at a rate by foreign key, so
     * unlike a business field this needs no in-use guard.
     */
    public function destroy(ManufacturingCostRate $manufacturingCostRate): JsonResponse
    {
        $this->orders->deleteManufacturingCostRate($manufacturingCostRate);

        return $this->successMessage('تم حذف المعدل بنجاح');
    }

    /**
     * A manufacturing cost rate's history
     */
    public function logs(
        ActivityLogFilterRequest $request,
        ManufacturingCostRate $manufacturingCostRate,
        AuditService $audit,
    ): JsonResponse {
        return $this->auditTrailResponse($request, $manufacturingCostRate, $audit);
    }
}
