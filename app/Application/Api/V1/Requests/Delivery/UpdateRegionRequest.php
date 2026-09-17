<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Requests\Delivery;

use App\Domain\Delivery\Models\City;
use App\Domain\Delivery\Models\Region;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Same shape as adding one; the uniqueness rule additionally ignores this region, so saving it
 * without renaming does not collide with itself.
 *
 * Written out in full rather than merged onto the parent's — Scramble reads `rules()` statically
 * and cannot follow `array_merge(parent::rules(), …)`.
 */
class UpdateRegionRequest extends StoreRegionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', $this->nameUniqueAmongTheCitysOtherRegions()],
            'code' => ['nullable', 'string', 'max:20'],
            'darb_branch' => ['nullable', 'string', 'max:255'],
            'nawris_area_id' => ['nullable', 'string', 'max:40'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
        ];
    }

    private function nameUniqueAmongTheCitysOtherRegions(): Unique
    {
        /** @var City $city */
        $city = $this->route('city');
        /** @var Region $region */
        $region = $this->route('region');

        return Rule::unique('regions', 'name')
            ->where('city_id', $city->getKey())
            ->ignore($region->getKey())
            ->withoutTrashed();
    }
}
