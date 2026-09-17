<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Queries;

use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Models\Shortage;
use App\Domain\Shortage\Queries\Concerns\FiltersShortages;

/**
 * How many shortages stand in each status — «جديد ١٢ | جاري البحث ٧ | غير متوفر ٣ | مكتمل ٢٥».
 *
 * **The status filter itself is dropped, and every other filter is kept.** A chip row exists to
 * say what else there is; counting only the status already selected would make every chip but one
 * read zero, and the row would answer a question nobody asked. Every *other* filter stays,
 * because «جديد ١٢» under a product filter has to mean twelve of that product — the same rule
 * `PurchaseOrderStatusCountsQuery` follows.
 *
 * **Including the archive guard**, which is exactly why the two queries share a trait: a count
 * that saw archived orders while the list below it did not would leak the archive through a
 * number — see SHORTAGES-DESIGN §٥.
 *
 * Every status is present in the result, zeros included, so the app draws a stable row rather
 * than one whose chips appear and disappear as work moves.
 */
final class ShortageStatusCountsQuery
{
    use FiltersShortages;

    /**
     * @return array<string, int>
     */
    public function __invoke(ShortageFilters $filters): array
    {
        $counts = $this->applyFilters(Shortage::query(), $filters->withoutStatuses())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = [];

        foreach (ShortageStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }
}
