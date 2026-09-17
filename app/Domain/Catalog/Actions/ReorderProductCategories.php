<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Support\Facades\DB;

/**
 * Puts the catalogue's headings in the order somebody just dragged them into.
 *
 * **The whole order arrives at once, not one row per request.** A drag moves one card and
 * renumbers everything after it; sending a request per moved row would be a burst of writes
 * where one is needed, and a dropped connection halfway through would leave the list in an order
 * nobody chose.
 *
 * **Positions are numbered by ten.** `sort_order` is what the API orders by, and leaving gaps
 * means a single row can later be nudged between two others by an import or a console command
 * without renumbering the table.
 *
 * Ids the caller did not mention are left exactly as they are: a screen showing one page of a
 * long list must be able to reorder that page without claiming anything about the rest.
 */
final class ReorderProductCategories
{
    /**
     * @param  list<int>  $orderedIds  in the order they should appear
     */
    public function __invoke(array $orderedIds): void
    {
        if ($orderedIds === []) {
            return;
        }

        DB::transaction(function () use ($orderedIds): void {
            foreach (array_values($orderedIds) as $position => $id) {
                ProductCategory::query()
                    ->whereKey($id)
                    ->update(['sort_order' => ($position + 1) * 10]);
            }
        });
    }
}
