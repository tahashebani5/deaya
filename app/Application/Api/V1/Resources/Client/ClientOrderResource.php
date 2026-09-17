<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources\Client;

use App\Application\Api\V1\Resources\OrderResource;
use App\Domain\Order\Enums\CustomerOrderStage;
use App\Domain\Order\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of «طلباتي».
 *
 * **`status` is absent and `stage` is in its place**, which is the single most important thing
 * about this resource. {@see OrderResource} sends `status` — the workshop's own word — and
 * «قيد التصنيع» on a customer's phone announces that we did not make their bags ourselves,
 * while «نواقص» announces that our shelf was empty. Neither is a lie and neither is theirs to
 * read. {@see CustomerOrderStage} is the translation, and it lives in the server so the app
 * cannot hold a second copy of it that drifts.
 *
 * What else is missing: every cost and profit column, the internal notes, the vendor, the
 * warehouse quantities, the carrier's own references. The staff resource guards several of
 * those with `$request->user()?->can(...)` — a call a customer cannot make at all.
 *
 * @mixin Order
 */
class ClientOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stage = CustomerOrderStage::forStatus($this->status);

        return [
            'id' => $this->id,
            // «1228» — what the customer reads out when they ring.
            'code' => $this->code,

            'stage' => $stage->value,
            'stage_label' => $stage->label(),
            // Saves the app a list of its own for the «قيد التنفيذ» filter.
            'is_open' => $stage->isOpen(),

            // The line the design draws under every card. Server-side beside `stage_label` for
            // the same reason: a `switch` over stages in Dart is the second copy of a mapping
            // this codebase keeps in one place.
            'stage_hint' => $stage->hint(),

            'items_count' => $this->whenCounted('items'),
            // A one-line summary so the list draws without the app fetching each order to write
            // a subtitle.
            'summary' => $this->whenLoaded('items', fn (): ?string => $this->summaryLine()),

            // **Null while any line is still to be quoted**, for the reason spelled out in
            // {@see ClientOrderDetailResource}: the stored figure is the sum of the priced lines
            // only, and a row in «طلباتي» showing it would quote the customer a number smaller
            // than what they will be asked to pay. The card draws «يُحدَّد بعد المراجعة».
            'total' => $this->hasUnpricedLines() ? null : (string) $this->grand_total,
            'is_awaiting_quote' => $this->hasUnpricedLines(),

            'placed_at' => $this->placed_at?->toIso8601String(),
        ];
    }

    /**
     * What the first line is, and how many others there are.
     *
     * A method rather than a column: it is presentation, and computing it here means the list
     * endpoint loads the lines once instead of the app opening every order to draw a subtitle.
     */
    protected function summaryLine(): ?string
    {
        $items = $this->items;

        if ($items->isEmpty()) {
            return null;
        }

        $first = $items->first();
        $name = trim("{$first->product_name} {$first->variant_label}");
        $rest = $items->count() - 1;

        return $rest > 0 ? "{$name} و{$rest} أخرى" : $name;
    }
}
