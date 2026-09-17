<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\DTOs\BusinessFieldData;
use App\Domain\Customer\Models\BusinessField;

/**
 * Replaces a field with what was sent.
 *
 * **Renaming is allowed even when shops point at it**, and that is deliberate: this is a
 * *label*, not a snapshot. An order records what it cost on the day; a shop records which
 * trade it is in, and fixing «بيع ملابس» spelt wrong should fix it everywhere at once.
 */
final class UpdateBusinessField
{
    public function __invoke(BusinessField $field, BusinessFieldData $data): BusinessField
    {
        $field->update([
            'name' => $data->name,
            'is_active' => $data->isActive,
            'sort_order' => $data->sortOrder,
        ]);

        return $field->loadCount('shops');
    }
}
