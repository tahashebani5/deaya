<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Inventory;

use App\Domain\Inventory\Enums\WarehouseType;
use App\Domain\Inventory\Models\Warehouse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Same shape as creating; only the uniqueness rule differs, because a warehouse keeping its own
 * name must not collide with itself.
 *
 * The rules are written out in full rather than merged onto the parent's on purpose: Scramble
 * reads this method statically to build the OpenAPI request body and cannot follow an
 * `array_merge(parent::rules(), …)` call — doing that leaves the endpoint with no documented body.
 */
class UpdateWarehouseRequest extends StoreWarehouseRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', $this->nameUniqueAmongOtherWarehouses()],
            'type' => ['required', Rule::enum(WarehouseType::class)],
            // Sending null clears the address — see UpdateWarehouse, which replaces the whole row.
            'location' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function nameUniqueAmongOtherWarehouses(): Unique
    {
        /** @var Warehouse $warehouse */
        $warehouse = $this->route('warehouse');

        return Rule::unique('warehouses', 'name')->ignore($warehouse->getKey())->withoutTrashed();
    }
}
