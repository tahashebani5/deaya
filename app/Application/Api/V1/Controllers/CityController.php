<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Controllers\Concerns\ReadsAuditTrail;
use App\Application\Api\V1\Requests\Audit\ActivityLogFilterRequest;
use App\Application\Api\V1\Requests\Delivery\StoreCityRequest;
use App\Application\Api\V1\Requests\Delivery\UpdateCityRequest;
use App\Application\Api\V1\Resources\CityResource;
use App\Application\Controller;
use App\Domain\Audit\AuditService;
use App\Domain\Delivery\DeliveryService;
use App\Domain\Delivery\DTOs\CityData;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Queries\CityFilters;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cities
 *
 * The delivery map's top level: where an order can go, and what it costs to send it there. The
 * two إستلام مكتب rows are cities too, priced at 0.00 — collecting in person is picked from the
 * same list as delivery, so the checkout has one choice to make rather than two.
 *
 * Reading needs `cities.view`; changing the map needs `cities.manage`. Both are declared on the
 * routes, so the guard on an endpoint is visible without opening this file.
 */
class CityController extends Controller
{
    use ReadsAuditTrail, ResponseTrait;

    public function __construct(private readonly DeliveryService $delivery) {}

    /**
     * List cities
     *
     * In delivery-map order, which starts with the office-pickup branches. `search` matches the
     * city name or its شركة درب branch. Each row carries `regions_count` rather than the regions
     * themselves — fetch one city to get those.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = CityFilters::fromArray($request->only(['search', 'is_region_required', 'has_price']));
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        return $this->successWithPagination(
            CityResource::collection($this->delivery->paginateCities($filters, $perPage)),
        );
    }

    /**
     * Create a city
     *
     * Regions are added afterwards through `/cities/{city}/regions`.
     */
    public function store(StoreCityRequest $request): JsonResponse
    {
        $city = $this->delivery->createCity(CityData::fromArray($request->validated()));

        return $this->created(new CityResource($city), 'تم إضافة المدينة بنجاح');
    }

    /**
     * Get one city
     *
     * With every region inside it, in name order.
     */
    public function show(City $city): JsonResponse
    {
        return $this->success(new CityResource($city->load('regions')->loadCount('regions')));
    }

    /**
     * Update a city
     *
     * The whole city is replaced by what is sent, so leaving `latitude`/`longitude` out clears
     * the pin rather than keeping it.
     */
    public function update(UpdateCityRequest $request, City $city): JsonResponse
    {
        $updated = $this->delivery->updateCity($city, CityData::fromArray($request->validated()));

        return $this->success(new CityResource($updated), 'تم تحديث المدينة بنجاح');
    }

    /**
     * Delete a city
     *
     * Every region inside it goes too — they have no life outside their city.
     */
    public function destroy(City $city): JsonResponse
    {
        $this->delivery->deleteCity($city);

        return $this->successMessage('تم حذف المدينة بنجاح');
    }

    /**
     * A city's history
     *
     * Every change to the city and to the regions inside it, newest first — including the
     * deletions, which is the point: a city is the one record here that a real destroy route
     * can remove, and this is what says who removed it.
     *
     * Filter with `event`, `causer_id`, `from` and `to`.
     */
    public function logs(ActivityLogFilterRequest $request, City $city, AuditService $audit): JsonResponse
    {
        return $this->auditTrailResponse($request, $city, $audit);
    }
}
