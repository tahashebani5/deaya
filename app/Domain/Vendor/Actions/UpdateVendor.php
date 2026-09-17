<?php

declare(strict_types=1);

namespace App\Domain\Vendor\Actions;

use App\Domain\Vendor\DTOs\VendorData;
use App\Domain\Vendor\Models\Vendor;

final class UpdateVendor
{
    public function __invoke(Vendor $vendor, VendorData $data): Vendor
    {
        $attributes = [
            'name' => $data->name,
            'phone' => $data->phone,
            'contact_person' => $data->contactPerson,
            'email' => $data->email,
            'address' => $data->address,
        ];

        // Only touched when the caller actually sent it, so an update that omits the field
        // cannot reactivate a vendor who was deliberately deactivated.
        if ($data->isActive !== null) {
            $attributes['is_active'] = $data->isActive;
        }

        $vendor->update($attributes);

        return $vendor;
    }
}
