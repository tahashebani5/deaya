<?php

declare(strict_types=1);

namespace App\Domain\Delivery\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Contracts\HasAuditTrail;
use Database\Factories\RegionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A neighbourhood inside a city — سوق الجمعة, زناتة, السبعة …
 *
 * It carries no price of its own. Delivery is priced per city; the region says where inside it,
 * and which شركة درب branch and zone code route the parcel.
 *
 * `city_id` is deliberately not fillable — a region is created through its city
 * (`$city->regions()->create(...)`), so a request can never move one to another city by posting
 * the key.
 */
#[UseFactory(RegionFactory::class)]
#[Fillable(['name', 'code', 'darb_branch', 'nawris_area_id', 'latitude', 'longitude'])]
class Region extends Model implements HasAuditTrail
{
    /** @use HasFactory<RegionFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
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
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
