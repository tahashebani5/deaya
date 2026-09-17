<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources\Client;

use App\Application\Api\V1\Resources\RegionResource;
use App\Domain\Delivery\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A neighbourhood, as the customer app draws it in a picker.
 *
 * **Three fields where the staff resource has nine.** `darb_branch` and `nawris_area_id` are the
 * names our carriers use for this place — which carrier a parcel goes with is an arrangement
 * between the shop and them, and publishing it to every customer publishes it to every
 * competitor. `latitude`/`longitude` are for a staff map this app does not draw, and the
 * timestamps are the catalogue's own housekeeping.
 *
 * {@see RegionResource} is where all of that lives.
 *
 * @mixin Region
 */
class ClientRegionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'city_id' => $this->city_id,
            'name' => $this->name,
        ];
    }
}
