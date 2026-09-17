<?php

declare(strict_types=1);

namespace App\Domain\Marketing;

use App\Domain\Marketing\Actions\CreateBillboard;
use App\Domain\Marketing\Actions\DeleteBillboard;
use App\Domain\Marketing\Actions\UpdateBillboard;
use App\Domain\Marketing\DTOs\BillboardData;
use App\Domain\Marketing\Models\Billboard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

/**
 * The Marketing module's public front door — what this shop says to its customers.
 *
 * **The two read methods are the module's whole shape**, and they are deliberately not one
 * method with a flag: `showingNow()` answers the customer app and applies the schedule, while
 * `all()` answers the management screen and applies nothing. A banner you cannot see is a banner
 * you cannot fix, so staff get every row — scheduled, expired, switched off — and a boolean
 * argument deciding that would be the kind of thing somebody passes the wrong way round once.
 */
class MarketingService
{
    public function __construct(
        private readonly CreateBillboard $createBillboard,
        private readonly UpdateBillboard $updateBillboard,
        private readonly DeleteBillboard $deleteBillboard,
    ) {}

    /**
     * The carousel, in the order staff arranged it. What the customer app is sent, and the only
     * thing it is sent.
     *
     * @return Collection<int, Billboard>
     */
    public function showingNow(): Collection
    {
        return Billboard::query()
            ->showingNow()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every banner, including the ones nobody can see.
     *
     * @return Collection<int, Billboard>
     */
    public function all(): Collection
    {
        return Billboard::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function find(int $id): Billboard
    {
        return Billboard::query()->findOrFail($id);
    }

    public function create(BillboardData $data, UploadedFile $image, ?int $createdBy = null): Billboard
    {
        return ($this->createBillboard)($data, $image, $createdBy);
    }

    public function update(Billboard $billboard, BillboardData $data, ?UploadedFile $image = null): Billboard
    {
        return ($this->updateBillboard)($billboard, $data, $image);
    }

    public function delete(Billboard $billboard): void
    {
        ($this->deleteBillboard)($billboard);
    }
}
