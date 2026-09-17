<?php

declare(strict_types=1);

namespace App\Domain\Order\Enums;

use App\Domain\Order\Actions\RecalculateOrderItemManufacturingCost;
use App\Domain\Order\Models\ProductionCostEntry;

/**
 * What kind of production cost a {@see ProductionCostEntry} records.
 *
 * `Labor` and `MachineRuntime` and `Overhead` are rate-driven — see `ApplyManufacturingRates`,
 * which looks up a {@see \App\Domain\Order\Models\ManufacturingCostRate} for each and applies it
 * automatically the moment an order enters ready. `ScrapLoss` is different in kind: its amount
 * is derived from FIFO batch cost when spoiled stock is written off, never from a rate — see the
 * plan's Inventory section for the endpoint that produces it (not part of this change).
 */
enum ManufacturingCostType: string
{
    case Labor = 'labor';
    case MachineRuntime = 'machine_runtime';
    case Overhead = 'overhead';
    case ScrapLoss = 'scrap_loss';

    /**
     * Goods made, counted, and left on the counter by the customer who ordered them — see
     * `RecordPartialDelivery`.
     *
     * **Its own kind rather than a second `ScrapLoss`**, though the two are recorded the same way
     * and reported side by side. Scrap is spoiled *during* production and never reached anybody;
     * this is finished work that was refused, and the difference is the only thing that makes
     * «لماذا خسرنا هذا الشهر؟» answerable — one is a press problem and the other is a
     * counter problem, and they are fixed by different people.
     *
     * **Priced from the line's own `cogs`, not from FIFO.** `ScrapLoss` reads what the spoiled
     * bags cost by consuming cost layers, because spoilage draws fresh stock off a shelf. Nothing
     * is drawn here: the goods left at «جاهزة» and were already costed onto the line, so the
     * loss is that line's share of what it cost — `cogs × undelivered ÷ quantity`. It is also the
     * only cost type that can arise on a وسيط line, which has no material cost at all and whose
     * `cogs` is its `outsourcing_cost`.
     */
    case DeliveryLoss = 'delivery_loss';

    public function label(): string
    {
        return match ($this) {
            self::Labor => 'عمالة',
            self::MachineRuntime => 'تشغيل آلة',
            self::Overhead => 'مصاريف عامة',
            self::ScrapLoss => 'خسارة تلف',
            self::DeliveryLoss => 'خسارة تسليم جزئي',
        };
    }

    /**
     * Whether a {@see ManufacturingCostRate} drives this cost type.
     *
     * The two losses never have one: a rate answers «what does an hour of this cost?», and
     * neither spoiled bags nor refused ones are bought by the hour.
     */
    public function isRateDriven(): bool
    {
        return ! $this->isLoss();
    }

    /**
     * Whether this entry records something lost rather than something spent producing.
     *
     * **The line the profit-and-loss statement is drawn along.** A loss is reported in its own
     * section and never summed into `order_items.labor_cost`/`overhead_cost` — see
     * {@see RecalculateOrderItemManufacturingCost}, which has always skipped `ScrapLoss` and
     * now skips both by asking this instead of naming one.
     *
     * Adding it to COGS would double-count: the undelivered bags' cost is already inside the
     * `material_cost` frozen at «جاهزة», and gross profit already falls because revenue fell
     * while that cost did not. What the section adds is a *name* for the money, not a second
     * subtraction of it.
     */
    public function isLoss(): bool
    {
        return $this === self::ScrapLoss || $this === self::DeliveryLoss;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
