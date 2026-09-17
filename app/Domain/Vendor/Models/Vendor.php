<?php

declare(strict_types=1);

namespace App\Domain\Vendor\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Comment\Concerns\HasComments;
use App\Domain\Comment\Models\Comment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Inventory\Models\Warehouse;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A supplier the business buys stock from.
 *
 * Deactivated, never deleted — the same rule {@see Customer} follows:
 * every {@see StockArrival} on record points at a vendor row, and deleting one would leave that
 * history pointing at nothing. There is deliberately no destroy route.
 */
#[UseFactory(VendorFactory::class)]
#[Fillable(['name', 'contact_person', 'phone', 'email', 'address', 'is_active'])]
class Vendor extends Model implements HasAuditTrail
{
    /** @use HasFactory<VendorFactory> */
    use Auditable, HasComments, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Every shipment ever bought from this vendor, oldest first.
     *
     * @return HasMany<StockArrival, $this>
     */
    public function stockArrivals(): HasMany
    {
        return $this->hasMany(StockArrival::class);
    }

    /**
     * A vendor's own history only — **the arrivals are deliberately not here.**
     *
     * Same reasoning {@see Warehouse::auditTrailSubjects()} gives for
     * excluding its movements: arrivals grow for as long as the business keeps buying from this
     * vendor, and `GET /stock-arrivals?vendor_id=` is the purpose-built, paginated reader for
     * that — plucking every id here would degrade a little more with every purchase.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [
            $this->getMorphClass() => [$this->getKey()],
            // The notes, unlike the arrivals: a supplier accumulates a handful of sentences and
            // an unbounded number of shipments. `withTrashed` deliberately — «من حذف الملاحظة؟»
            // is a question about this supplier, and a removed note is the only kind anyone goes
            // looking for.
            (new Comment)->getMorphClass() => $this->comments()->withTrashed()->pluck('id')->all(),
        ];
    }
}
