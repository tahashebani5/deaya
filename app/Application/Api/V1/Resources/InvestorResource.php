<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Investor\Models\Investor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Investor
 */
class InvestorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // What staff say out loud — «حساب I7» — and the safe half of this row to read down a
            // phone line.
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,

            // Whether he can sign in. A fact about the row rather than a role name, so renaming
            // the role cannot change what the app believes.
            'has_login' => $this->user_id !== null,

            // What he has with us and what he has earned — two numbers, drawn on his row.
            //
            // `capital` is **both places his capital can be**, his wallet and the deals it is
            // committed to, because from where he stands they are one sum he handed over; the
            // two are told apart on his own screen, where the distinction is the point. Present
            // on the list only, which is the screen that asked for it.
            'totals' => $this->when(isset($this->totals), fn (): ?array => $this->totals),

            // Present only where the caller asked for balances — a list of fifty investors does
            // not walk fifty ledgers to draw a table.
            // **A list, not a map keyed by deal id.** An array with integer keys does not
            // survive the trip to JSON as an object — the resource layer re-indexes a
            // single-entry map into a list — so every row names the deal it belongs to instead
            // of relying on its position. Self-describing on the wire, and impossible to
            // silently relabel.
            'balances' => $this->when(isset($this->balances), fn (): array => [
                'wallet' => $this->balances['wallet'],
                'deals' => array_map(
                    fn (int $dealId): array => [
                        'investor_deal_id' => $dealId,
                        'capital' => $this->balances['deals'][$dealId]['capital'],
                        'profit' => $this->balances['deals'][$dealId]['profit'],
                    ],
                    array_keys($this->balances['deals']),
                ),
            ]),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
