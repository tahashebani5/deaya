<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\CustomerData;
use App\Domain\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;

final class UpdateCustomer
{
    public function __construct(private readonly SyncCustomerShops $syncShops) {}

    public function __invoke(Customer $customer, CustomerData $data): Customer
    {
        return DB::transaction(function () use ($customer, $data): Customer {
            $attributes = [
                'name' => $data->name,
                'phone' => $data->phone,
            ];

            // Only touched when the caller actually sent it, so an update that omits the
            // field cannot reactivate a customer who was deliberately deactivated.
            if ($data->isActive !== null) {
                $attributes['is_active'] = $data->isActive;
            }

            $customer->update($attributes);

            // A null `shops` means the caller did not mention shops at all, so they keep
            // whatever they have. An empty array is an explicit instruction to clear them.
            if ($data->shops !== null) {
                ($this->syncShops)($customer, $data->shops);
            }

            // The code is derived from the id and therefore never changes on update.
            return $customer->load(Customer::SHOP_RELATIONS);
        });
    }
}
