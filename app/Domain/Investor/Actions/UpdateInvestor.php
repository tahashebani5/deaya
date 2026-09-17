<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Investor\DTOs\InvestorData;
use App\Domain\Investor\Models\Investor;

final class UpdateInvestor
{
    public function __invoke(Investor $investor, InvestorData $data): Investor
    {
        $investor->fill([
            'name' => $data->name,
            'phone' => $data->phone,
            'notes' => $data->notes,
        ])->save();

        return $investor;
    }
}
