<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTOs\ShippingCompanyData;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Delivery\Support\SoleDefaultCarrier;
use Illuminate\Support\Facades\DB;

final class UpdateShippingCompany
{
    public function __invoke(ShippingCompany $company, ShippingCompanyData $data): ShippingCompany
    {
        $isDefault = SoleDefaultCarrier::wanted($data, current: (bool) $company->is_default);

        return DB::transaction(function () use ($company, $data, $isDefault): ShippingCompany {
            // Cleared *before* this row is written, and inside the same transaction: the index
            // that allows one default is checked per statement, so the order is the difference
            // between moving the flag and being refused for holding it twice.
            if ($isDefault) {
                SoleDefaultCarrier::clearAllBut($company->getKey());
            }

            $company->update([
                'name' => $data->name,
                'phone' => $data->phone,
                'notes' => $data->notes,
                'is_active' => $data->isActive,
                'is_default' => $isDefault,
            ]);

            return $company;
        });
    }
}
