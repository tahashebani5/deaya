<?php

declare(strict_types=1);

namespace App\Domain\Customer\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use Database\Factories\CustomerShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place where a customer sells — اسم المكان / المدينة والمنطقة / رابط الصفحة.
 *
 * Owned entirely by its customer; it has no independent lifecycle and is managed
 * through the customer's own endpoints.
 *
 * **Where it is, is a row on the delivery map** — the same `cities`/`regions` an order is
 * addressed from, so a shop and the order going to it speak about place in one vocabulary, and
 * «كم عميلاً في زناتة؟» is a `group by` rather than an afternoon with a map.
 *
 * The coordinates are still here and still cast, holding what was recorded while the form asked
 * for a pin. Nothing writes them today; nothing erases them either.
 *
 * Audited but not a {@see HasAuditTrail}: there is no screen for one
 * shop, so its entries are read through `GET /customers/{customer}/logs`.
 */
#[UseFactory(CustomerShopFactory::class)]
#[Fillable(['name', 'city_id', 'region_id', 'latitude', 'longitude', 'page_url', 'business_field_id'])]
class CustomerShop extends Model
{
    /** @use HasFactory<CustomerShopFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * Cast to float, not the default decimal-as-string: a map SDK expects numbers, and the
     * API should hand it numbers rather than "32.8872000".
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * مجال العمل — the trade this shop is in, or none.
     *
     * @return BelongsTo<BusinessField, $this>
     */
    public function businessField(): BelongsTo
    {
        return $this->belongsTo(BusinessField::class);
    }

    /**
     * المدينة — where this shop is, on the delivery map.
     *
     * Required on the way in, so `city_id` always points at a row. The relation can still
     * resolve to null: a city that has been soft-deleted is out of the map's scope, and a shop
     * left pointing at one shows no city until somebody re-picks it.
     *
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * المنطقة — which neighbourhood inside the city, when it is known.
     *
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
