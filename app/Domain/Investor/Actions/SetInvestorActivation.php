<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Investor\Models\Investor;

/**
 * Retires an investor, or brings one back.
 *
 * There is no delete route, the `business_fields` rule: a person with money in the ledger cannot
 * be removed without the ledger losing its subject, and a row somebody should not have created
 * is a different problem from a partner who is no longer active.
 */
final class SetInvestorActivation
{
    public function __invoke(Investor $investor, bool $isActive): Investor
    {
        $investor->fill(['is_active' => $isActive])->save();

        return $investor;
    }
}
