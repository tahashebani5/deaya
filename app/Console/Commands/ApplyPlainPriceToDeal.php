<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Investor\InvestorService;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Support\StockPurchaseMargins;
use App\Domain\Order\Actions\RecalculateOrderCogs;
use App\Domain\Order\Actions\RecalculateOrderItemCost;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\MaterialCost;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * Puts a deal that was funded without سعر السادة onto the buying road, and repairs the printed
 * lines that already drew on it.
 *
 * **Why a command rather than an edit screen.** `printing_sale_price` is written once, by
 * {@see \App\Domain\Investor\Actions\FundPurchaseOrder}, and frozen with the percentages — it is
 * a term the men putting money in were shown, and a deal whose terms can be retyped is not a
 * term at all. D1 was funded on 2026-09-05, before the column existed, so it has been riding the
 * sale itself: every printed order it stocked paid its investor a share of the *order's* profit,
 * printing and all. That is what the owner asked to stop on 2026-09-11 — «نبي نفصل ربح طباعة وربح
 * لبضاعة» — and this is the one-off repair for the deals caught on the wrong side of the date.
 * A deal that already has a price is refused: renegotiating a live deal is a new deal.
 *
 * **What it does, in the order it must be done:**
 *
 * 1. the price onto the deal, and onto every cost layer that arrived under it — the layer is
 *    where {@see \App\Domain\Inventory\Queries\ConsumptionBreakdownQuery} reads it from, so a
 *    layer left null is a kilo the press never bought;
 * 2. every **printed** line that already drew on one of those layers is re-costed through
 *    {@see MaterialCost}, exactly as {@see \App\Domain\Order\Actions\DeductOrderStock} would have
 *    costed it on the day: `material_cost` becomes what the press pays, `material_cost_actual`
 *    keeps what the goods cost, and `stock_purchased_at` is stamped with the moment the stock
 *    actually left rather than today;
 * 3. {@see \App\Domain\Investor\Actions\PostDealStockPurchases} is run for each of those orders,
 *    which pays the investors their share of the margin and — being keyed on the line — will
 *    correct itself if the press later restates the run.
 *
 * **A سادة line is never touched**, whichever deal stocked it: it is sold off the shelf as it
 * stands, so its investor keeps riding the sale. Neither is a **closed** order — «تم الاستلام»
 * and «تم التسوية» have already paid their investors out of the order's profit, and that money is
 * corrected with a reversal entry by somebody who has decided to, never by a repair script
 * rewriting the figure underneath it. Such orders are named in the report instead.
 *
 * `--dry-run` writes nothing and reports the same figures, computed through the same pure
 * arithmetic ({@see MaterialCost}, {@see StockPurchaseMargins}, {@see InvestorDeal::investorsCutOf()})
 * rather than through a rolled-back transaction — the reasoning {@see DropDeliveryFromOrderTotals}
 * gives for the same choice.
 */
class ApplyPlainPriceToDeal extends Command
{
    use ConfirmableTrait;

    protected $signature = 'investors:apply-plain-price
                            {deal : The deal id or code, e.g. 1 or D1}
                            {price : سعر السادة in the shelf unit, e.g. 32}
                            {--dry-run : Report what would change and write nothing}
                            {--force : Skip the confirmation prompt outside local}';

    protected $description = 'Set سعر السادة on a deal funded without one, and re-cost the printed lines that already drew on it';

    /** The statuses whose investor money is already settled. See the class docblock. */
    private const CLOSED = [
        OrderStatus::Delivered->value,
        OrderStatus::Settled->value,
        OrderStatus::Cancelled->value,
    ];

    public function handle(
        InventoryService $inventory,
        InvestorService $investors,
        RecalculateOrderItemCost $recalculateItemCost,
        RecalculateOrderCogs $recalculateCogs,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        $deal = $this->deal();

        if ($deal === null) {
            $this->error('لا توجد صفقة بهذا الرقم: '.$this->argument('deal'));

            return self::FAILURE;
        }

        if ($deal->printing_sale_price !== null) {
            $this->error("الصفقة {$deal->code} عليها سعر سادة بالفعل: {$deal->printing_sale_price}");

            return self::FAILURE;
        }

        $price = number_format((float) $this->argument('price'), 3, '.', '');

        if (bccomp($price, '0.000', 3) <= 0) {
            $this->error('سعر السادة يجب أن يكون أكبر من صفر.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $dealId = (int) $deal->getKey();
        $layers = StockBatch::query()->where('investor_deal_id', $dealId)->count();

        if (! $dryRun) {
            $deal->forceFill(['printing_sale_price' => $price])->save();

            // Saved one at a time rather than mass-updated: `StockBatch` keeps an audit trail,
            // and a layer's price is exactly the kind of figure somebody will want to see the
            // history of.
            StockBatch::query()
                ->where('investor_deal_id', $dealId)
                ->each(fn (StockBatch $batch) => $batch->forceFill(['printing_sale_price' => $price])->save());
        }

        [$repaired, $skipped] = $this->repair(
            $deal,
            $price,
            $dryRun,
            $inventory,
            $recalculateItemCost,
            $recalculateCogs,
        );

        if (! $dryRun) {
            foreach (array_unique(array_column($repaired, 'order_id')) as $orderId) {
                $investors->postStockPurchasesForOrder((int) $orderId);
            }
        }

        $this->report($deal, $price, $layers, $repaired, $skipped, $dryRun);

        return self::SUCCESS;
    }

    /** The deal named on the command line, by id or by code. */
    private function deal(): ?InvestorDeal
    {
        $argument = (string) $this->argument('deal');

        return InvestorDeal::query()
            ->when(
                ctype_digit($argument),
                fn ($q) => $q->whereKey((int) $argument),
                fn ($q) => $q->where('code', $argument),
            )
            ->first();
    }

    /**
     * Re-costs every printed line that already drew on this deal's layers.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, string>>} what was
     *                                                                             repaired, and what was left alone with the reason why
     */
    private function repair(
        InvestorDeal $deal,
        string $price,
        bool $dryRun,
        InventoryService $inventory,
        RecalculateOrderItemCost $recalculateItemCost,
        RecalculateOrderCogs $recalculateCogs,
    ): array {
        $repaired = [];
        $skipped = [];

        foreach ($this->linesDrawnFrom((int) $deal->getKey()) as $item) {
            $order = $item->order;

            // Asked before the order's status, because a سادة line is untouched whatever the
            // order is doing — reporting it as «مقفلة» would name the wrong reason.
            if (! $item->isPrinted()) {
                $skipped[] = ['order' => (string) $order->code, 'reason' => 'بند سادة — يبقى راكباً البيعة نفسها'];

                continue;
            }

            if (in_array((string) $order->status->value, self::CLOSED, true)) {
                $skipped[] = [
                    'order' => (string) $order->code,
                    'reason' => 'الطلبية مقفلة ('.$order->status->label().') — تُصحَّح بقيد عكسي لا بسكربت',
                ];

                continue;
            }

            $movementId = (int) $item->fulfillment_stock_movement_id;
            $draws = $this->pricedDraws(
                $inventory->consumptionBreakdownFor([$movementId])[$movementId] ?? [],
                (int) $deal->getKey(),
                $price,
            );

            $cost = MaterialCost::forDraws($draws, true);

            if (! $cost->purchased) {
                $skipped[] = ['order' => (string) $order->code, 'reason' => 'لا سحب مسعَّر على هذا البند'];

                continue;
            }

            $margin = StockPurchaseMargins::byDeal($draws)[(int) $deal->getKey()] ?? '0.00';

            $repaired[] = [
                'order_id' => (int) $order->getKey(),
                'order' => (string) $order->code,
                'quantity' => (string) $item->producedQuantity(),
                'was' => (string) $item->material_cost,
                'now' => $cost->charged,
                'margin' => $margin,
                'investors' => $deal->investorsCutOf($margin),
            ];

            if ($dryRun) {
                continue;
            }

            $item->forceFill([
                'material_cost' => $cost->charged,
                'material_cost_actual' => $cost->actual,
                // The moment the goods actually left, not the moment this repair ran: every
                // reader of this column is asking when the press took them.
                'stock_purchased_at' => $order->stock_deducted_at ?? now(),
            ])->save();

            ($recalculateItemCost)($item);

            // Only where the order already has one. `total_cogs` is written on the way into
            // «جاهزة»; an order that has not reached it keeps its null rather than gaining a
            // total this repair invented.
            if ($order->total_cogs !== null) {
                ($recalculateCogs)($order);
            }
        }

        return [$repaired, $skipped];
    }

    /**
     * The lines whose fulfilment draw touched this deal's layers and that have not been costed as
     * a purchase yet.
     *
     * **A reversed movement is not one of them**: its goods went back on the shelf, so there is
     * no purchase to record and the line is holding nothing.
     *
     * @return \Illuminate\Support\Collection<int, OrderItem>
     */
    private function linesDrawnFrom(int $dealId): \Illuminate\Support\Collection
    {
        return OrderItem::query()
            ->whereNotNull('fulfillment_stock_movement_id')
            ->whereNull('stock_purchased_at')
            ->whereIn('fulfillment_stock_movement_id', fn ($q) => $q
                ->select('c.stock_movement_id')
                ->from('stock_batch_consumptions as c')
                ->join('stock_batches as b', 'b.id', '=', 'c.stock_batch_id')
                ->where('b.investor_deal_id', $dealId)
                ->whereNull('c.deleted_at')
                ->whereNull('b.deleted_at'))
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('stock_movements as r')
                ->whereColumn('r.reverses_movement_id', 'order_items.fulfillment_stock_movement_id')
                ->whereNull('r.deleted_at'))
            ->with(['order', 'product.productCategory.parent'])
            ->orderBy('order_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * The draws as they will read once the layers carry the price — so a dry run prices exactly
     * what the write would, and the write itself re-reads it off the layers it has just set.
     *
     * @param  list<array<string, mixed>>  $draws
     * @return list<array<string, mixed>>
     */
    private function pricedDraws(array $draws, int $dealId, string $price): array
    {
        return array_map(function (array $draw) use ($dealId, $price): array {
            if ($draw['investor_deal_id'] === $dealId && $draw['printing_sale_price'] === null) {
                $draw['printing_sale_price'] = $price;
            }

            return $draw;
        }, $draws);
    }

    /**
     * @param  list<array<string, mixed>>  $repaired
     * @param  list<array<string, string>>  $skipped
     */
    private function report(
        InvestorDeal $deal,
        string $price,
        int $layers,
        array $repaired,
        array $skipped,
        bool $dryRun,
    ): void {
        $this->newLine();
        $this->line($dryRun ? '— تجربة، لم يُكتب شيء —' : '— نُفِّذ —');
        $this->line("الصفقة {$deal->code} · سعر السادة {$price} · الطبقات {$layers}");
        $this->newLine();

        if ($repaired !== []) {
            $this->table(
                ['الطلبية', 'الكمية', 'المادة قبل', 'المادة بعد', 'هامش الصفقة', 'للمستثمرين'],
                array_map(fn (array $row) => [
                    $row['order'], $row['quantity'], $row['was'], $row['now'], $row['margin'], $row['investors'],
                ], $repaired),
            );

            $this->line('الإجمالي للمستثمرين: '.array_reduce(
                $repaired,
                fn (string $carry, array $row): string => bcadd($carry, $row['investors'], 2),
                '0.00',
            ));
        } else {
            $this->line('لا بنود تحتاج تصحيحاً.');
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->line('تُركت كما هي:');
            $this->table(['الطلبية', 'السبب'], array_map(fn (array $row) => [$row['order'], $row['reason']], $skipped));
        }
    }
}
