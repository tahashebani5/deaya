<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Delivery\StoreRegionRequest;
use App\Application\Api\V1\Requests\Delivery\UpdateRegionRequest;
use App\Application\Api\V1\Resources\RegionResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Delivery\DeliveryService;
use App\Domain\Delivery\DTOs\RegionData;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Regions
 *
 * The neighbourhoods inside a city — سوق الجمعة, زناتة, السبعة … A region carries no price of
 * its own; delivery is priced per city, and the region only says where inside it, plus which
 * شركة درب branch and zone code route the parcel.
 *
 * Every route is nested under its city, so a region belonging to a different one is a 404 by
 * construction rather than by a check each method has to remember.
 *
 * Reading needs `cities.view`; changing the map needs `cities.manage`. A region is never
 * administered by anyone who is not also administering its city, so the two share one pair of
 * permissions rather than inventing a second.
 */
class RegionController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly DeliveryService $delivery) {}

    /**
     * List a city's regions
     *
     * In name order. `search` matches the region name.
     */
    public function index(Request $request, City $city): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);
        $search = trim((string) $request->query('search', ''));

        return $this->successWithPagination(
            RegionResource::collection($this->delivery->paginateRegions($city, $search ?: null, $perPage)),
        );
    }

    /**
     * Add a region to a city
     *
     * The city comes from the URL — a `city_id` in the body is ignored, so a request can never
     * file a neighbourhood under a city it was not posted to.
     */
    public function store(StoreRegionRequest $request, City $city): JsonResponse
    {
        $region = $this->delivery->createRegion($city, RegionData::fromArray($request->validated()));

        return $this->created(new RegionResource($region), 'تم إضافة المنطقة بنجاح');
    }

    /**
     * Get one region
     */
    public function show(City $city, Region $region): JsonResponse
    {
        return $this->success(new RegionResource($region));
    }

    /**
     * Update a region
     *
     * The whole region is replaced by what is sent. A region cannot be moved to another city.
     */
    public function update(UpdateRegionRequest $request, City $city, Region $region): JsonResponse
    {
        $updated = $this->delivery->updateRegion($region, RegionData::fromArray($request->validated()));

        return $this->success(new RegionResource($updated), 'تم تحديث المنطقة بنجاح');
    }

    /**
     * Delete a region
     *
     * Refused with 422 when it is the last one in a city that requires a region to be chosen —
     * that would leave checkout asking the customer to pick from an empty list. Clear the city's
     * `is_region_required` first.
     */
    public function destroy(City $city, Region $region): JsonResponse
    {
        $this->delivery->deleteRegion($region);

        return $this->successMessage('تم حذف المنطقة بنجاح');
    }

    /**
     * A region's history
     *
     * Every change to this one neighbourhood, newest first. The city it belongs to has a wider
     * history at `GET /cities/{city}/logs`, which includes all of its regions.
     *
     * Filter with `event`, `causer_id`, `from` and `to`.
     */
    public function logs(
        ActivityLogFilterRequest $request,
        City $city,
        Region $region,
        AuditService $audit,
    ): JsonResponse {
        return $this->auditTrailResponse($request, $region, $audit);
    }
}
