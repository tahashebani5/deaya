<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Reporting\Queries\ProfitAndLossFilters;
use App\Domain\Reporting\Queries\ProfitAndLossSummaryQuery;
use App\Domain\Reporting\Queries\SalesStatisticsFilters;
use App\Domain\Reporting\Queries\SalesStatisticsQuery;

/**
 * The Reporting module's public front door.
 *
 * Unlike every other module's service, this one's queries reach across context boundaries by
 * design — see {@see ProfitAndLossSummaryQuery}'s own docblock for why. The door still exists:
 * a controller calls this, never a query class directly, so a second report can be added here
 * without every caller having to know which query class answers which question.
 */
class ReportingService
{
    public function __construct(
        private readonly ProfitAndLossSummaryQuery $profitAndLossSummary,
        private readonly SalesStatisticsQuery $salesStatistics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function profitAndLossSummary(ProfitAndLossFilters $filters): array
    {
        return ($this->profitAndLossSummary)($filters);
    }

    /**
     * حجم المبيعات وحركة الأكياس over a period — the second report to come through this door, and
     * the reason the door was worth having.
     *
     * @return array<string, mixed>
     */
    public function salesStatistics(SalesStatisticsFilters $filters): array
    {
        return ($this->salesStatistics)($filters);
    }
}
