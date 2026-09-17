<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\CustomerData;
use App\Domain\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;

final class CreateCustomer
{
    public function __construct(
        private readonly AllocateCustomerIdentifier $allocateIdentifier,
        private readonly SyncCustomerShops $syncShops,
    ) {}

    public function __invoke(CustomerData $data): Customer
    {
        return DB::transaction(function () use ($data): Customer {
            $identifier = ($this->allocateIdentifier)();

            $customer = new Customer([
                'name' => $data->name,
                'phone' => $data->phone,
                // Not supplied means active — a new customer is someone you just started
                // working with.
                'is_active' => $data->isActive ?? true,
            ]);

            // Assigned directly rather than mass-assigned: neither the key nor the code may
            // ever come from a request.
            $customer->id = $identifier->id;
            $customer->code = $identifier->code;
            $customer->save();

            ($this->syncShops)($customer, $data->shops ?? []);

            return $customer->load(Customer::SHOP_RELATIONS);
        });
    }
}
