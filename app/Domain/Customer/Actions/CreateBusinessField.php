<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\BusinessFieldData;
use App\Domain\Customer\Models\BusinessField;

final class CreateBusinessField
{
    public function __invoke(BusinessFieldData $data): BusinessField
    {
        $field = BusinessField::create([
            'name' => $data->name,
            'is_active' => $data->isActive,
            'sort_order' => $data->sortOrder,
        ]);

        // A brand-new field has none, but the count must still be present: the resource renders
        // it, and strict mode turns a missing attribute into an exception rather than a null.
        return $field->loadCount('shops');
    }
}
