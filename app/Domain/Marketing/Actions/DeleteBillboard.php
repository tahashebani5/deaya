<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\Models\Billboard;

/**
 * Takes a banner down.
 *
 * **A real delete route, unlike customers and products** — and the reason is that nothing points
 * at a poster. There is no order whose history depends on this row still existing, so a banner
 * put up by mistake comes down rather than being lived with. Soft deleted all the same, so the
 * audit trail still names who took it down.
 *
 * The file is left on the disk, like a replaced picture in {@see UpdateBillboard}: the row can
 * be restored, and an orphan sweep is a decision for the day there is enough of them to matter.
 */
final class DeleteBillboard
{
    public function __invoke(Billboard $billboard): void
    {
        $billboard->delete();
    }
}
