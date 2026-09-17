<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Controllers;

use App\Application\Api\V1\Requests\Reporting\ProfitAndLossReportRequest;
use App\Application\Controller;
use App\Domain\Reporting\Queries\ProfitAndLossFilters;
use App\Domain\Reporting\ReportingService;
use App\Support\ResponseTrait;
use Illuminate\Http\JsonResponse;

/**
 * Profit & loss
 *
 * [Total Revenue (printed bags + design services)] − [Total COGS (raw material + manufacturing)]
 * = Gross Profit, over a period. Revenue is recognised on delivery or settlement; cash actually
 * collected in the same window is reported alongside, not netted against cost — see
 * `ProfitAndLossSummaryQuery`'s own docblock for why the two are kept apart.
 */
class ProfitAndLossController extends Controller
{
    use ResponseTrait;

    public function __construct(private readonly ReportingService $reporting) {}

    /**
     * Summarise a period
     *
     * `from` and `to` are both required and inclusive of their whole day.
     */
    public function summary(ProfitAndLossReportRequest $request): JsonResponse
    {
        $summary = $this->reporting->profitAndLossSummary(
            ProfitAndLossFilters::fromArray($request->validated()),
        );

        return $this->success($summary);
    }
}
