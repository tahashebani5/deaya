<?php

declare(strict_types=1);

namespace App\Domain\Investor\Actions;

use App\Domain\Investor\DTOs\InvestorData;
use App\Domain\Investor\Models\Investor;

/** Adds the person whose money we are about to hold. */
final class CreateInvestor
{
    public function __invoke(InvestorData $data, ?int $actorId): Investor
    {
        $investor = new Investor([
            'name' => $data->name,
            'phone' => $data->phone,
            'notes' => $data->notes,
            'is_active' => true,
        ]);

        // Stamped, never fillable: who added a record is not something the record's own payload
        // gets to claim. RULES §9.4.
        $investor->created_by = $actorId;
        $investor->save();

        return $investor;
    }
}
