<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\CascadesSoftDeletes;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Inventory\Enums\WarehouseType;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place stock is held.
 *
 * Deletable, which puts it in the same small group as a city — the only other record here a real
 * destroy route can remove. Like a city, it takes what has no life outside it: a warehouse's
 * balance rows go with it, while its movements stay, because a movement is history and history
 * is not owned by the place it happened in.
 */
#[UseFactory(WarehouseFactory::class)]
#[Fillable(['name', 'type', 'location'])]
class Warehouse extends Model implements HasAuditTrail
{
    /** @use HasFactory<WarehouseFactory> */
    use Auditable, CascadesSoftDeletes, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WarehouseType::class,
        ];
    }

    /**
     * The balance rows this warehouse holds — one per size that has ever been here.
     *
     * Ordered by variant so a stock screen renders the same way twice running; the table has no
     * sort column of its own, and unordered results are only incidentally stable.
     *
     * @return HasMany<WarehouseStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class)->orderBy('stock_item_id');
    }

    /**
     * Everything that has ever arrived here.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function incomingMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'to_warehouse_id');
    }

    /**
     * Everything that has ever left.
     *
     * @return HasMany<StockMovement, $this>
     */
    public function outgoingMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'from_warehouse_id');
    }

    /**
     * Whether anything is still on the shelves.
     *
     * Rows at zero do not count: a size that was here and has all been used up leaves a row
     * behind, and refusing to delete a warehouse because of a balance of 0.000 would make the
     * destroy route unusable after the first month.
     */
    public function holdsStock(): bool
    {
        return $this->stocks()->where('quantity', '>', 0)->exists();
    }

    /**
     * The balance rows go when the warehouse does — they describe what is in *this* place, so
     * they mean nothing without it.
     *
     * The movements deliberately stay. A movement records that stock left one site for another
     * on a Tuesday; deleting the site later does not make that untrue, and the counterpart
     * warehouse's history would develop a hole exactly where the transfer it received used to be.
     *
     * @return list<string>
     */
    public function softDeleteCascades(): array
    {
        return ['stocks'];
    }

    /**
     * A warehouse's history is its own plus its balance rows' — a threshold was set on the
     * warehouse's screen, so that is where someone will look for who set it.
     *
     * **The movements are deliberately not here.** A city can list its 61 regions into an `IN`
     * clause and stop growing; a warehouse's movement list grows for as long as the business
     * trades, and plucking every id would degrade a little more each month until the endpoint
     * times out. The ledger has a purpose-built reader — `GET /stock-movements?warehouse_id=` —
     * which pages and filters properly, and that is the right place to read it.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [
            $this->getMorphClass() => [$this->getKey()],
            (new WarehouseStock)->getMorphClass() => $this->stocks()->withTrashed()->pluck('id')->all(),
        ];
    }
}
