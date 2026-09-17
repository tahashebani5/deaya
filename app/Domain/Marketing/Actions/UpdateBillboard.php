<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Actions;

use App\Domain\Marketing\DTOs\BillboardData;
use App\Domain\Marketing\Models\Billboard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Edits a banner, and swaps its picture when a new one is sent.
 *
 * **A field left out is a field left alone.** Every property on {@see BillboardData} is nullable
 * and null means «not supplied», so `PATCH {is_active: false}` switches a banner off without
 * clearing its schedule — which is the one edit staff make most and the one a naive `update()`
 * would get wrong.
 *
 * The exception is the destination pair, handled together: setting either side clears the other,
 * because the database refuses a row carrying both.
 */
final class UpdateBillboard
{
    public function __construct(private readonly UploadBillboardImage $uploadImage) {}

    public function __invoke(Billboard $billboard, BillboardData $data, ?UploadedFile $image = null): Billboard
    {
        return DB::transaction(function () use ($billboard, $data, $image): Billboard {
            $attributes = [];

            if ($image !== null) {
                // The old object is left where it is. A banner's picture is cheap and a campaign
                // half-swapped is worse than a file nobody reads — a sweep can collect orphans
                // later, once there is a reason to.
                $attributes = ($this->uploadImage)($image);
            }

            foreach ([
                'title' => $data->title,
                'sort_order' => $data->sortOrder,
                'is_active' => $data->isActive,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->endsAt,
            ] as $column => $value) {
                if ($value !== null) {
                    $attributes[$column] = $value;
                }
            }

            // One destination, never two — the database's own check says the same thing.
            if ($data->productId !== null) {
                $attributes['product_id'] = $data->productId;
                $attributes['external_url'] = null;
            } elseif ($data->externalUrl !== null) {
                $attributes['external_url'] = $data->externalUrl;
                $attributes['product_id'] = null;
            }

            $billboard->update($attributes);

            return $billboard->refresh();
        });
    }
}
