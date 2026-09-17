<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Customer\Exceptions\BusinessFieldInUse;
use App\Domain\Customer\Models\BusinessField;

/**
 * Deletes a field nothing is using.
 *
 * The guard is here rather than in the controller so it holds for every caller — a console
 * command, an import, a future bulk tidy-up — and not only for the HTTP route where somebody
 * remembered to write it.
 *
 * Soft, like every delete in this codebase: the row and its history survive, and a field that
 * was removed by mistake is restorable straight from the database.
 */
final class DeleteBusinessField
{
    public function __invoke(BusinessField $field): void
    {
        // Trashed shops count too — see BusinessField::isInUse().
        $inUse = $field->shops()->withTrashed()->count();

        if ($inUse > 0) {
            throw BusinessFieldInUse::make($field->name, $inUse);
        }

        $field->delete();
    }
}
