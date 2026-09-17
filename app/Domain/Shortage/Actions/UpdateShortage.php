<?php

declare(strict_types=1);

namespace App\Domain\Shortage\Actions;

use App\Domain\Shortage\DTOs\ShortageData;
use App\Domain\Shortage\Exceptions\ShortageIsNotEditable;
use App\Domain\Shortage\Exceptions\ShortageNeedsAQuantity;
use App\Domain\Shortage\Exceptions\SupplyExceedsRemaining;
use App\Domain\Shortage\Models\Shortage;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a hand-written shortage.
 *
 * **Refused outright on an order-born one** — see {@see ShortageIsNotEditable}. The name, the
 * unit and the requirement there are copied off the line and rewritten on every sync, so an edit
 * would survive until the next time anybody touched that order and then silently vanish.
 *
 * **The requirement may not be cut below what has already been supplied.** Twenty kilos bought
 * and a requirement corrected to ten would leave the shortage owing minus ten, which
 * `remainingQuantity()` floors at zero and `RecalculateShortageTotals` then reads as complete —
 * so the correction would quietly close a shortage by making its own history impossible. The
 * honest way down is to reverse the purchase that is no longer wanted.
 *
 * The assignee is deliberately not here: it has its own action, its own permission, and its own
 * endpoint, because routing work is a different job from describing it.
 */
final class UpdateShortage
{
    public function __construct(private readonly RecalculateShortageTotals $recalculate) {}

    /**
     * @throws ShortageIsNotEditable
     * @throws ShortageNeedsAQuantity
     * @throws SupplyExceedsRemaining
     */
    public function __invoke(Shortage $shortage, ShortageData $data): Shortage
    {
        if (! $shortage->isEditable()) {
            throw ShortageIsNotEditable::make();
        }

        if (bccomp($data->requiredQuantity, '0', 3) <= 0) {
            throw ShortageNeedsAQuantity::make();
        }

        return DB::transaction(function () use ($shortage, $data): Shortage {
            $locked = Shortage::query()->whereKey($shortage->getKey())->lockForUpdate()->firstOrFail();

            // Read off the locked row rather than the bound one: the model that arrived was read
            // before the lock was taken, so its `supplied_quantity` is what was true when the
            // request started — and a supply recorded in between is exactly the case this guard
            // is about.
            if (bccomp($data->requiredQuantity, (string) $locked->supplied_quantity, 3) < 0) {
                throw SupplyExceedsRemaining::belowWhatIsSupplied(
                    $data->requiredQuantity,
                    (string) $locked->supplied_quantity,
                );
            }

            $locked->forceFill([
                'name' => $data->name,
                'unit' => $data->unit,
                'required_quantity' => $data->requiredQuantity,
                'product_id' => $data->productId,
                'product_variant_id' => $data->productVariantId,
                'description' => $data->description,
            ])->save();

            // The requirement moved, so «مكتمل» may have become true — or stopped being true.
            // Same restatement every other writer of this row ends with.
            return ($this->recalculate)($locked);
        });
    }
}
