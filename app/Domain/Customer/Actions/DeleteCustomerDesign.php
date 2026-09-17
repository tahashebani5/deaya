<?php

declare(strict_types=1);

namespace App\Domain\Customer\Actions;

use App\Domain\Catalog\Actions\DeleteProductImage;
use App\Domain\Customer\Models\CustomerDesign;

/**
 * Removes a design from the picker — and leaves the file exactly where it is.
 *
 * **The opposite of {@see DeleteProductImage}, on purpose.** That one erases the object, because
 * a replaced product photo is worth nothing and storage is not free. This one must not: an order
 * placed last year points at a design, and the colleague tidying today's list has no idea which
 * ones. Erasing the bytes would leave an order that cannot show what it printed.
 *
 * So there is nothing to do here but hide the row. The action exists anyway rather than letting
 * the controller call `delete()`, because the *absence* of a file deletion is the decision, and
 * a decision needs somewhere to be written down.
 */
final class DeleteCustomerDesign
{
    public function __invoke(CustomerDesign $design): void
    {
        $design->delete();
    }
}
