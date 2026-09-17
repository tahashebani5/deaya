<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Investor\Enums\DealStatus;
use App\Domain\Investor\Exceptions\DealIsNotEditable;
use App\Domain\Investor\Models\InvestorDeal;

/**
 * Opens a deal for business — and closes its terms in the same breath.
 *
 * Past this point the shelves, the participants and the percentages are what the money will be
 * split by, so they stop being editable. Goods may now be claimed for it and cost layers may
 * carry it.
 */
final class OpenInvestorDeal
{
    public function __invoke(InvestorDeal $deal): InvestorDeal
    {
        if ($deal->status !== DealStatus::Draft) {
            throw DealIsNotEditable::make((string) $deal->code);
        }

        $deal->status = DealStatus::Open;
        $deal->opened_at = now();
        $deal->save();

        return $deal;
    }
}
