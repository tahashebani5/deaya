<?php

declare(strict_types=1);

namespace App\Domain\Shortage;

use App\Domain\Identity\Models\User;
use App\Domain\Order\OrderService;
use App\Domain\Shortage\Actions\AssignShortage;
use App\Domain\Shortage\Actions\ChangeShortageStatus;
use App\Domain\Shortage\Actions\CreateShortage;
use App\Domain\Shortage\Actions\RecordShortageSupply;
use App\Domain\Shortage\Actions\ReverseShortageSupply;
use App\Domain\Shortage\Actions\UpdateShortage;
use App\Domain\Shortage\DTOs\ShortageData;
use App\Domain\Shortage\DTOs\ShortageSupplyData;
use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Models\Shortage;
use App\Domain\Shortage\Models\ShortageSupply;
use App\Domain\Shortage\Queries\ShortageFilters;
use App\Domain\Shortage\Queries\ShortageListQuery;
use App\Domain\Shortage\Queries\ShortageStatusCountsQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Shortage module's public front door.
 *
 * **This context depends on Order and Order does not know it exists.** It reads lines through
 * {@see OrderService} and writes back through the same door the order screen uses, and Orders
 * announces rather than calls — so the dependency runs one way even though the two write to each
 * other's tables in the course of one request. See Docs/shortages/SHORTAGES-DESIGN.md §٣.
 *
 * Nothing outside reads shortages yet. The day a report does, it arrives here.
 */
class ShortageService
{
    public function __construct(
        private readonly CreateShortage $createShortage,
        private readonly UpdateShortage $updateShortage,
        private readonly ChangeShortageStatus $changeStatus,
        private readonly AssignShortage $assignShortage,
        private readonly RecordShortageSupply $recordSupply,
        private readonly ReverseShortageSupply $reverseSupply,
        private readonly ShortageListQuery $listQuery,
        private readonly ShortageStatusCountsQuery $statusCountsQuery,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Shortage>
     */
    public function paginate(ShortageFilters $filters, int $perPage = 15): LengthAwarePaginator
    {
        return ($this->listQuery)($filters, $perPage);
    }

    /**
     * How many shortages stand in each status, under the same filters as the list beside it.
     *
     * @return array<string, int>
     */
    public function statusCounts(ShortageFilters $filters): array
    {
        return ($this->statusCountsQuery)($filters);
    }

    public function create(ShortageData $data, ?User $actor = null): Shortage
    {
        return ($this->createShortage)($data, $actor);
    }

    public function update(Shortage $shortage, ShortageData $data): Shortage
    {
        return ($this->updateShortage)($shortage, $data);
    }

    public function changeStatus(Shortage $shortage, ShortageStatus $target): Shortage
    {
        return ($this->changeStatus)($shortage, $target);
    }

    /**
     * Assign, reassign, or take a shortage out of everybody's queue.
     *
     * A null assignee is the third of those and is a legitimate request, not a missing argument —
     * which is why it is one method rather than an `assign`/`unassign` pair.
     */
    public function assign(Shortage $shortage, ?User $assignee, ?User $actor = null): Shortage
    {
        return ($this->assignShortage)($shortage, $assignee, $actor);
    }

    public function recordSupply(
        Shortage $shortage,
        ShortageSupplyData $data,
        ?User $actor = null,
    ): ShortageSupply {
        return ($this->recordSupply)($shortage, $data, $actor);
    }

    public function reverseSupply(
        Shortage $shortage,
        ShortageSupply $supply,
        ?string $reason = null,
        ?User $actor = null,
    ): ShortageSupply {
        return ($this->reverseSupply)($shortage, $supply, $reason, $actor);
    }

    /**
     * Everything ever recorded against a shortage, reversals included, oldest first.
     *
     * **Reversals are in**, unlike the collection the totals are built from: §٩ of the brief asks
     * for a log of every operation, and a correction that vanished from the very screen meant to
     * explain the numbers would make a shortage that reads «١٠ كجم» after two entries of twenty
     * look like a mistake rather than a recorded one.
     *
     * @return Collection<int, ShortageSupply>
     */
    public function supplies(Shortage $shortage): Collection
    {
        return $shortage->supplies()
            ->with(['recorder', 'warehouse'])
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * A shortage with everything its own screen draws, in one go.
     *
     * `order` is loaded for the archive check as much as for rendering — see
     * {@see Shortage::belongsToAnArchivedOrder()}.
     */
    public function loadForDisplay(Shortage $shortage): Shortage
    {
        return $shortage->load([
            'order',
            'customer',
            'product',
            'productVariant',
            'assignee',
            'creator',
            'supplies.recorder',
            'supplies.warehouse',
        ]);
    }
}
