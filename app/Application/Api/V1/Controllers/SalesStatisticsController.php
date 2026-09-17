<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Reporting\SalesStatisticsReportRequest;
use App\Application\Controller;
use App\Domain\Reporting\Queries\SalesStatisticsFilters;
use App\Domain\Reporting\ReportingService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * إحصائيات المبيعات
 *
 * حجم المبيعات وحركة الأكياس over a period: what was sold in dinars split سادة/مطبوع, the weight
 * of each type of bag in kilograms, and — kept separate because it is the figure the printing
 * engineer's share will one day be computed from — how many printed bags that was, by the piece.
 *
 * Bags only. Everything دعاية sells that is not a bag is وسيط work, and it is left off — see
 * `SalesStatisticsQuery::BAG_MODES` for how that is decided and what to change when it stops
 * being true.
 */
class SalesStatisticsController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly ReportingService $reporting) {}

    /**
     * Summarise a period
     *
     * `from` and `to` are both required and inclusive of their whole day.
     */
    public function summary(SalesStatisticsReportRequest $request): JsonResponse
    {
        $statistics = $this->reporting->salesStatistics(
            SalesStatisticsFilters::fromArray($request->validated()),
        );

        return $this->success($statistics);
    }
}
