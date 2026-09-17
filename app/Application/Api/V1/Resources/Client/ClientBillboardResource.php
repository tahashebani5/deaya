<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources\Client;

use App\Application\Api\V1\Resources\BillboardResource;
use App\Domain\Marketing\Models\Billboard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A banner, as the customer app draws it: a picture and where tapping it leads.
 *
 * **The schedule is absent, not hidden.** `starts_at`, `ends_at`, `is_active` and `sort_order`
 * are how staff arrange the carousel; the app is sent only what is showing, in order, so a start
 * date it cannot act on tells it nothing and tells a competitor when the campaign began.
 * {@see BillboardResource} is where all of that lives.
 *
 * **`target` is a decided answer rather than two nullable columns.** The app should not have to
 * infer «this leads nowhere» from `product_id === null && external_url === null` — that is a
 * rule, and a rule on the client is a rule in two places. So the server names the case.
 *
 * @mixin Billboard
 */
class ClientBillboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Doubles as the alt text a screen reader announces, which is why it is never null.
            'title' => $this->displayName(),
            'image_url' => $this->imageUrl(),
            'width_px' => $this->width_px,
            'height_px' => $this->height_px,

            'target' => $this->target(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function target(): array
    {
        if ($this->product_id !== null) {
            return ['type' => 'product', 'product_id' => $this->product_id];
        }

        if ($this->external_url !== null) {
            return ['type' => 'url', 'url' => $this->external_url];
        }

        return ['type' => 'none'];
    }
}
