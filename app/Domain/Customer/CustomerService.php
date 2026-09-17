<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use App\Domain\Customer\Actions\CreateBusinessField;
use App\Domain\Customer\Actions\CountCustomerBadges;
use App\Domain\Customer\Actions\CreateCustomer;
use App\Domain\Customer\Actions\DeleteBusinessField;
use App\Domain\Customer\Actions\UpdateBusinessField;
use App\Domain\Customer\Actions\UpdateCustomer;
use App\Domain\Customer\DTOs\BusinessFieldData;
use App\Domain\Customer\DTOs\CustomerData;
use App\Domain\Customer\Exceptions\BusinessFieldInUse;
use App\Domain\Customer\Models\BusinessField;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Customer\Queries\BusinessFieldFilters;
use App\Domain\Customer\Queries\BusinessFieldListQuery;
use App\Domain\Customer\Queries\CustomerFilters;
use App\Domain\Customer\Queries\CustomerListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The Customer module's public front door.
 *
 * Other modules (Orders, Production, …) talk to customers *only* through this class — they
 * never query the Customer model directly. That is what keeps the dependency one-way and
 * lets the module's internals change without a ripple. Within the module, work still lives
 * in the individual Actions and Queries; this is the seam, not a place for logic.
 */
class CustomerService
{
    public function __construct(
        private readonly CreateCustomer $createCustomer,
        private readonly UpdateCustomer $updateCustomer,
        private readonly CustomerListQuery $listQuery,
        private readonly CreateBusinessField $createBusinessField,
        private readonly UpdateBusinessField $updateBusinessField,
        private readonly DeleteBusinessField $deleteBusinessField,
        private readonly BusinessFieldListQuery $businessFieldListQuery,
        private readonly CountCustomerBadges $countBadges,
    ) {}

    /**
     * What is waiting for one customer, as a number per badge.
     *
     * Through this door rather than the action directly, like everything else a controller
     * reaches: the client API asks Customer, and Customer asks Support. A controller calling
     * `SupportService` for a badge would be the app's screens deciding which contexts exist.
     *
     * @return array<string, int>
     */
    public function badgesFor(int $customerId): array
    {
        return ($this->countBadges)($customerId);
    }

    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    public function paginate(CustomerFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->listQuery)($filters, $perPage);
    }

    /**
     * How many customers the shop has.
     *
     * Everyone, including the ones no longer sold to: a customer is deactivated rather than
     * deleted, and «عدد العملاء» is a count of the people this business has dealt with. Soft
     * deleted rows are excluded by the model's global scope, as they are from every list.
     */
    public function count(): int
    {
        return Customer::query()->count();
    }

    public function find(int $id): Customer
    {
        return Customer::query()->findOrFail($id);
    }

    /**
     * One design from a customer's library.
     *
     * Whether it belongs to the customer placing the order is the caller's rule — Order throws
     * its own refusal for that, because "not this customer's artwork" is an order's problem to
     * describe, not the customer module's.
     */
    public function findDesign(int $id): CustomerDesign
    {
        return CustomerDesign::query()->findOrFail($id);
    }

    public function create(CustomerData $data): Customer
    {
        return ($this->createCustomer)($data);
    }

    public function update(Customer $customer, CustomerData $data): Customer
    {
        return ($this->updateCustomer)($customer, $data);
    }

    /**
     * Customers are deactivated rather than deleted, so their history survives.
     */
    public function setActive(Customer $customer, bool $isActive): Customer
    {
        $customer->update(['is_active' => $isActive]);

        return $customer->load(Customer::SHOP_RELATIONS);
    }

    // ─────────────────────────── مجالات العمل ───────────────────────────
    //
    // The trades a customer's shop can be in. Reference data rather than a customer, but it
    // exists only to describe one, so it comes through the same front door instead of a module
    // of its own for a single lookup table.

    /**
     * @return LengthAwarePaginator<int, BusinessField>
     */
    public function paginateBusinessFields(
        BusinessFieldFilters $filters,
        int $perPage = 15,
    ): LengthAwarePaginator {
        return ($this->businessFieldListQuery)($filters, $perPage);
    }

    public function createBusinessField(BusinessFieldData $data): BusinessField
    {
        return ($this->createBusinessField)($data);
    }

    public function updateBusinessField(BusinessField $field, BusinessFieldData $data): BusinessField
    {
        return ($this->updateBusinessField)($field, $data);
    }

    /**
     * Hides a field from the pickers without touching the shops already recorded under it.
     *
     * The ordinary way to retire one — {@see DeleteBusinessField} refuses outright once any
     * shop points at it.
     */
    public function setBusinessFieldActive(BusinessField $field, bool $isActive): BusinessField
    {
        $field->update(['is_active' => $isActive]);

        return $field->loadCount('shops');
    }

    /**
     * For the row that should never have existed — a typo, a duplicate.
     *
     * Throws {@see BusinessFieldInUse} when shops are recorded
     * under it; deactivation is what that case wants.
     */
    public function deleteBusinessField(BusinessField $field): void
    {
        ($this->deleteBusinessField)($field);
    }
}
