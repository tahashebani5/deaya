<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Concerns\CascadesSoftDeletes;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Delivery\Enums\FulfilmentType;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place an order can be delivered to, priced as a whole.
 *
 * The two إستلام مكتب rows are cities too, at 0.00 — the customer collecting the order in person
 * is the same choice as having it delivered, made from the same list, so it is not a separate
 * concept the checkout has to branch on.
 *
 * A city *is* deletable, which makes it the model soft deletes matter most to: it is the one
 * place a real destroy route exists, and deleting one used to take its regions with it for good.
 * Now both are recoverable, and the trail records who did it.
 */
#[UseFactory(CityFactory::class)]
#[Fillable(['name', 'fulfilment_type', 'is_region_required', 'delivery_price', 'darb_branch', 'nawris_government_id', 'latitude', 'longitude'])]
class City extends Model implements HasAuditTrail
{
    /** @use HasFactory<CityFactory> */
    use Auditable, CascadesSoftDeletes, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fulfilment_type' => FulfilmentType::class,
            'is_region_required' => 'boolean',
            // String, not float: this price is added to an order total, and money that is summed
            // must stay exact.
            'delivery_price' => 'decimal:2',
            // Float, not the default decimal-as-string: a map SDK expects numbers, and the API
            // should hand it numbers rather than "32.8872000".
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Ordered by name so a picker renders the same way every time — the table has no explicit
     * sort column, and unordered results are only incidentally stable.
     *
     * @return HasMany<Region, $this>
     */
    public function regions(): HasMany
    {
        return $this->hasMany(Region::class)->orderBy('name');
    }

    /** A city nobody has agreed a rate for yet — quoting must refuse rather than guess. */
    public function hasDeliveryPrice(): bool
    {
        return $this->delivery_price !== null;
    }

    /**
     * One of our branches, collected from in person.
     *
     * The single question orders ask of the delivery map, so it is answered here rather than by
     * every caller reaching for the enum — and by the column rather than by the name it used to
     * be guessed from. See {@see FulfilmentType}.
     */
    public function isOfficePickup(): bool
    {
        return $this->fulfilment_type->isOfficePickup();
    }

    /**
     * A region has no life outside its city, so it goes when the city does — the rule the
     * foreign key used to enforce, kept working now that nothing is really deleted.
     *
     * @return list<string>
     */
    public function softDeleteCascades(): array
    {
        return ['regions'];
    }

    /**
     * A city's history includes its regions'. They are added, renamed and removed through the
     * city's own screen, so that is where someone will look for what happened to them.
     *
     * @return array<string, list<int|string>>
     */
    public function auditTrailSubjects(): array
    {
        return [
            $this->getMorphClass() => [$this->getKey()],
            (new Region)->getMorphClass() => $this->regions()->withTrashed()->pluck('id')->all(),
        ];
    }
}
