<?php

declare(strict_types=1);

namespace App\Domain\Vendor\Actions;

use App\Domain\Vendor\DTOs\VendorData;
use App\Domain\Vendor\Models\Vendor;

final class CreateVendor
{
    public function __invoke(VendorData $data): Vendor
    {
        return Vendor::create([
            'name' => $data->name,
            'phone' => $data->phone,
            'contact_person' => $data->contactPerson,
            'email' => $data->email,
            'address' => $data->address,
            // Not supplied means active — a new vendor is someone you just started buying from.
            'is_active' => $data->isActive ?? true,
        ]);
    }
}
