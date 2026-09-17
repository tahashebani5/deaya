<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\DTOs\ShippingCompanyData;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Delivery\Support\SoleDefaultCarrier;
use Illuminate\Support\Facades\DB;

final class CreateShippingCompany
{
    public function __invoke(ShippingCompanyData $data): ShippingCompany
    {
        // **A new company is not the default unless it was asked for.** Being added is not being
        // preferred, and `null` here is silence rather than a request — see the DTO.
        $isDefault = SoleDefaultCarrier::wanted($data, current: false);

        return DB::transaction(function () use ($data, $isDefault): ShippingCompany {
            if ($isDefault) {
                SoleDefaultCarrier::clearAllBut(null);
            }

            return ShippingCompany::create([
                'name' => $data->name,
                'phone' => $data->phone,
                'notes' => $data->notes,
                'is_active' => $data->isActive,
                'is_default' => $isDefault,
            ]);
        });
    }
}
