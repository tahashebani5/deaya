<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Models\ShippingCompany;
use Illuminate\Support\Facades\DB;

/**
 * Removes a carrier from the list.
 *
 * **Nothing cascades, and that is the point.** An order that went out with this company keeps
 * saying so: the key is left where it is and the name beside it is a snapshot, exactly as the
 * city's is. Deleting a company changes what may be *chosen tomorrow*, never what happened.
 *
 * The ordinary way to stop using a carrier is `is_active = false`. This is for the row that
 * should not have existed — a duplicate, a typo — and it soft deletes like everything here.
 */
final class DeleteShippingCompany
{
    public function __invoke(ShippingCompany $company): void
    {
        DB::transaction(fn () => $company->delete());
    }
}
