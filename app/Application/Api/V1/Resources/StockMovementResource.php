<?php

declare(strict_types=1);

namespace App\Application\Api\V1\Resources;

use App\Domain\Inventory\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'movement_type' => $this->movement_type->value,
            'movement_type_label' => $this->movement_type->label(),

            // Only ever filled on an adjustment that removed stock. Null everywhere else says
            // «this row explains itself by its type», which is the truth for the other five.
            'adjustment_reason' => $this->adjustment_reason?->value,
            'adjustment_reason_label' => $this->adjustment_reason?->label(),

            // Which fulfillment this credited back. Published so a ledger screen can mark the
            // pair rather than leaving two rows that look like unrelated traffic.
            'reverses_movement_id' => $this->reverses_movement_id,

            // Always positive. Which of the two warehouse fields is null is what says whether
            // this added or removed — see the migration for the four shapes.
            'quantity' => (string) $this->quantity,

            // **The two ledger columns, computed by the list query and only when they mean
            // something.** The sign is relative to the warehouse the reader asked for — a
            // transfer is `+` on one shelf and `-` on the other — so it is null when no
            // warehouse was named; the balance is what one shelf held once this row had
            // happened, and null unless the request was scoped to a warehouse *and* a shelf.
            // See MovementListQuery.
            'signed_quantity' => $this->decimal('signed_quantity', 3),
            'balance_after' => $this->decimal('balance_after', 3),

            // **What the stock on this row cost, for a reader allowed to know.** Absent rather
            // than null for everybody else, and the two are different facts: a missing key means
            // «you may not be told», null means «nobody recorded it» — a movement older than the
            // cost layers, which the app reads as «غير معروف» and never as free.
            //
            // `unit_cost` is dropped whenever part of the row is unpriced: an average that
            // counts zeros is a number that describes nothing, so the app is handed the total
            // it can vouch for and the quantity it cannot, and says both.
            'unit_cost' => $this->when($this->costIsSelected(), fn (): ?string => $this->cost()?->unitCost),
            'total_cost' => $this->when($this->costIsSelected(), fn (): ?string => $this->cost()?->totalCost),
            'uncosted_quantity' => $this->when(
                $this->costIsSelected(),
                fn (): ?string => $this->cost()?->uncostedQuantity,
            ),

            // **Whose goods went out, when the movement drew from the shelf.** One entry per
            // owner — a deal by its code, and the company as a null id — because an order taking
            // a thousand bags off two layers took them off two people. Absent on a movement that
            // consumed nothing, and on the list a caller may not price: the quantities are
            // everybody's, the costs are `stock.cost.view`'s.
            'investor_draws' => $this->when(
                isset($this->investor_draws),
                fn (): array => array_map(
                    fn (array $draw): array => [
                        'investor_deal_id' => $draw['investor_deal_id'],
                        'code' => $draw['code'],
                        'quantity' => $draw['quantity'],
                        ...($this->costIsSelected() ? ['total_cost' => $draw['total_cost']] : []),
                    ],
                    $this->investor_draws,
                ),
            ),

            'stock_item_id' => $this->stock_item_id,
            // What the quantity is counted in. «1.6» on a feed that mixes bags and kilos is
            // not a number until this says which. Read off the item rather than the balance
            // line: past creation the unit is only ever rewritten by SetStockItemUnit, which
            // restates every balance with it, so the two never disagree.
            'unit' => $this->whenLoaded('stockItem', fn (): string => $this->stockItem->unit->value),
            'unit_label' => $this->whenLoaded('stockItem', fn (): string => $this->stockItem->unit->label()),
            // What moved: a shelf, not a product's size. Which product a movement was ultimately
            // for is `reference_id` and the order behind it — two products can draw on one pile,
            // so the item alone was never going to say.
            'stock_item' => $this->whenLoaded('stockItem', fn (): array => [
                'id' => $this->stockItem->id,
                // The code, because it is what staff say out loud — «عندك S7؟» — and the one
                // thing on this row that is safe to read down a phone line.
                'code' => $this->stockItem->code,
                'name' => $this->stockItem->name,
                'width_cm' => $this->stockItem->width_cm,
                'height_cm' => $this->stockItem->height_cm,
                'display_name' => $this->stockItem->displayName(),
            ]),

            'from_warehouse_id' => $this->from_warehouse_id,
            'from_warehouse' => $this->whenLoaded(
                'fromWarehouse',
                fn (): ?array => $this->fromWarehouse === null ? null : [
                    'id' => $this->fromWarehouse->id,
                    'name' => $this->fromWarehouse->name,
                ],
            ),

            'to_warehouse_id' => $this->to_warehouse_id,
            'to_warehouse' => $this->whenLoaded(
                'toWarehouse',
                fn (): ?array => $this->toWarehouse === null ? null : [
                    'id' => $this->toWarehouse->id,
                    'name' => $this->toWarehouse->name,
                ],
            ),

            // The order this belongs to, once Orders lands. Null on everything today.
            'reference_id' => $this->reference_id,

            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn (): array => [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'employee_code' => $this->employee->employee_code,
            ]),

            'notes' => $this->notes,

            // No `updated_at`: a ledger entry is never updated, so publishing one would only
            // invite a client to believe it could be.
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * A column the list query may or may not have selected, as a fixed-scale string — or null
     * when it was not asked for. `getAttributes()` rather than `getAttribute()`: strict mode
     * throws on a column that was never selected, and on a movement returned straight from a
     * write none of these are.
     */
    private function decimal(string $column, int $scale): ?string
    {
        $value = $this->resource->getAttributes()[$column] ?? null;

        return $value === null ? null : bcadd((string) $value, '0', $scale);
    }

    /**
     * Whether the query ran the cost aggregates at all — which it does exactly when the reader
     * holds `inventory.view_cost`. The controller decides; this only reads the consequence.
     */
    private function costIsSelected(): bool
    {
        return array_key_exists('consumed_quantity', $this->resource->getAttributes());
    }

    /**
     * Which side of the cost ledger this row is on, and what it says.
     *
     * A row that drew layers down (an issue, a transfer, a scrap, a downward count) is priced
     * by what FIFO took; a row that opened layers (an arrival, an upward count) by what it was
     * booked at. A transfer read from the shelf that received it has no layers of its own —
     * relocated batches keep the arrival's id — and falls through to the consumption side,
     * which is the same stock at the same cost. A row with neither is older than the cost
     * ledger, and the honest answer is null.
     */
    private function cost(): ?MovementCost
    {
        $attributes = $this->resource->getAttributes();

        if (bccomp((string) ($attributes['consumed_quantity'] ?? '0'), '0', 3) > 0) {
            return MovementCost::of(
                (string) $attributes['consumed_quantity'],
                (string) $attributes['consumed_total_cost'],
                (string) $attributes['consumed_uncosted_quantity'],
            );
        }

        if (bccomp((string) ($attributes['batch_quantity'] ?? '0'), '0', 3) > 0) {
            return MovementCost::of(
                (string) $attributes['batch_quantity'],
                (string) $attributes['batch_total_cost'],
                (string) $attributes['batch_uncosted_quantity'],
            );
        }

        return null;
    }
}

/**
 * The three cost figures a ledger row carries, at the scales the rest of the API uses: money to
 * two places, quantities to three.
 */
final readonly class MovementCost
{
    private function __construct(
        public ?string $unitCost,
        public string $totalCost,
        public string $uncostedQuantity,
    ) {}

    public static function of(string $quantity, string $totalCost, string $uncostedQuantity): self
    {
        $uncosted = bcadd($uncostedQuantity, '0', 3);

        return new self(
            unitCost: bccomp($uncosted, '0', 3) > 0 ? null : bcdiv($totalCost, $quantity, 3),
            totalCost: bcadd($totalCost, '0', 2),
            uncostedQuantity: $uncosted,
        );
    }
}
