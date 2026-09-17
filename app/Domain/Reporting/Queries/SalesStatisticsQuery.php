<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\Money;
use App\Domain\Order\Support\TransitionFields;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * حجم المبيعات وحركة الأكياس over a period — what was sold in dinars, split سادة/مطبوع, and how
 * many kilograms and pieces of it left the building.
 *
 * **Reads across `Order` and the catalogue directly rather than through their services** — the
 * same documented exception to RULES.md §3 that {@see ProfitAndLossSummaryQuery} takes, for the
 * same reason: this context exists to aggregate across boundaries, and routing each figure
 * through a service method would relocate the coupling rather than remove it.
 *
 * **Everything on the board comes out of one grouped query.** The totals are not summed
 * separately from the rows beneath them — they are folded up *from* those rows in PHP. So the
 * type table always adds up to the comparison card, which always adds up to the headline, and no
 * combination of rounding or filtering can make a reader's arithmetic disagree with ours. It is
 * the one property a board of totals-and-breakdowns has to have, and deriving the breakdown
 * first is the only way to guarantee it.
 *
 * ## What counts as a sale
 *
 * Delivered or settled, dated on `COALESCE(delivered_at, settled_at)` — {@see recognized()}, and
 * that method is deliberately the *only* place it is said. Cancelled orders are excluded by
 * construction rather than by a special case: `cancelled` is simply not on the list.
 *
 * ## What counts as a bag
 *
 * **Anything not وسيط** — see {@see BAG_MODES}. Today that is exactly right and tomorrow it may
 * not be, which is why it is one named constant rather than a condition scattered through six
 * selects; the note on the constant says what to do when it stops being true.
 *
 * ## Where the kilograms come from
 *
 * A printed bag is sold by the piece and stocked by the kilo — «كيس شحن سادة ٢٥*٣٥ وكيس شحن
 * مطبوع ٢٥*٣٥ صنفان في الكتالوج وكومة واحدة في المخزن», in `DeductOrderStock`'s own words. So
 * {@see OrderItem::isStockedInAnotherUnit()} is true for a printed line, the move into «جاهزة»
 * demands a scale reading, and `DeductOrderStock::guardEveryLineIsMeasured()` refuses to let the
 * goods go without one. That reading — `warehouse_quantity` — *is* the printed weight. Nothing
 * is derived from a bag-weight table, because the shop already weighs every run.
 *
 * **The shelf's unit is checked before any of it is believed.** `producedQuantity()` is in
 * whatever unit the shelf counts in, and not every shelf counts in kilograms — several groups in
 * the catalogue are stocked by the piece. Summing those in would print a piece count wearing a
 * kilogram label: «٥٠٠ كجم» for five hundred bags, the exact lie
 * {@see TransitionFields} goes out of its way not to tell. A line whose shelf is counted in
 * anything but kilograms contributes no weight at all, and says so through the coverage figure
 * rather than by quietly adding zero.
 *
 * ## Why coverage is on the board at all
 *
 * Three kinds of line legitimately have no weight: work a vendor made (nothing of ours was ever
 * on a shelf, so nobody weighed anything), a size with no shelf to point at, and a shelf counted
 * in pieces. Without a coverage figure a month full of any of them shows a weight that looks
 * wrong beside its own dinar figure, and no reader can tell «باعوا قليلاً» from «ما وزنوهش». It
 * is reported as a share of *value*, because value is what it is read against.
 */
final class SalesStatisticsQuery
{
    /**
     * What the board treats as a bag.
     *
     * **«ليس وسيطاً» is today's answer to «هل هو كيس؟», and the two are not the same question.**
     * Every product دعاية sells that is not a bag — كروت, ستيكرات, طباعة فقط — is filed under a
     * heading marked `outsourced`, and every bag under one marked `in_house` or `none`. So one
     * condition answers both «أظهر الأكياس فقط» and «لا تُظهر الوسيط», which is why there is one
     * condition here and not two.
     *
     * **It stops being true the day دعاية sells a bag through a vendor, or prints a sticker
     * in-house.** At that point this needs a fact of its own to read — a flag on the category
     * saying whether its goods belong on this board — rather than a longer list here. Whoever
     * hits that: add the column, seed it true for the أكياس headings, and turn this constant into
     * a `where` on it. Nothing else on this board moves.
     *
     * @var list<string>
     */
    private const BAG_MODES = [ProductionMode::InHouse->value, ProductionMode::None->value];

    /**
     * «هل يُطبع؟» resolved exactly as {@see ProductCategory::productionMode()} resolves it, parent
     * inheritance included — a child left at the default takes its parent's answer, and a child
     * that names one is never overruled. A product filed under no heading at all is `in_house`,
     * the same generous default {@see OrderItem::isPrinted()} gives it.
     *
     * **Asked of the line, never of the order.** `ResolveOrderFlow` puts a whole order on the
     * printing road for one printed line among five plain ones — right for the road, wrong for
     * the money, because it would count five plain lines' dinars as مطبوع.
     */
    private const MODE_SQL = "COALESCE(NULLIF(cat.production_mode, 'in_house'), parent_cat.production_mode, 'in_house')";

    /** The material a line draws on, falling back until something names it. */
    private const TYPE_SQL = 'COALESCE(stock_item_groups.name, stock_items.name, order_items.product_name)';

    /**
     * {@see OrderItem::billableQuantity()} in SQL — what was ordered, less what came up short,
     * floored at zero.
     *
     * The same trade-off `Order\Support\PaymentStatusExpression` already makes and documents: a
     * report reading across every order cannot load each one into PHP to ask it one arithmetic
     * question. `line_total` needs no such treatment — it is already derived from this.
     */
    private const BILLABLE_SQL = 'GREATEST(order_items.quantity - COALESCE(order_items.shortage_quantity, 0), 0)';

    /**
     * What this line weighs, or nothing at all.
     *
     * **The measured figure wins wherever there is one.** `warehouse_quantity` is what the scale
     * said on the way into «جاهزة», recorded after any shortage was known, so it is already the
     * weight that left the building rather than the weight that was ordered.
     *
     * **In its absence the billable quantity is the weight** — but only on a shelf counted in
     * kilograms, where the line was sold by weight in the first place and the two are the same
     * number. That is the سادة case, and it is why the fallback is the *billable* quantity rather
     * than `producedQuantity()`'s raw one: what was never delivered was never sold, and this
     * board must not weigh it.
     */
    private const WEIGHT_SQL = "CASE WHEN stock_items.unit = 'kilogram' THEN COALESCE(order_items.warehouse_quantity, ".self::BILLABLE_SQL.') END';

    /** Weights carry three places, like every quantity column they are summed out of. */
    private const KG_SCALE = 3;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(SalesStatisticsFilters $filters): array
    {
        $rows = $this->breakdown($filters);

        $printed = $this->fold($rows, ProductionMode::InHouse);
        $plain = $this->fold($rows, ProductionMode::None);

        $value = Money::sum($printed['value'], $plain['value']);
        $weight = self::addKg($printed['weight_kg'], $plain['weight_kg']);
        $valueWithWeight = Money::sum($printed['value_with_weight'], $plain['value_with_weight']);

        return [
            'period' => [
                'from' => $filters->from->toDateString(),
                'to' => $filters->to->toDateString(),
            ],
            'sales_value' => [
                'plain' => Money::round($plain['value']),
                'printed' => Money::round($printed['value']),
                'total' => Money::round($value),
            ],
            'by_type' => $this->byType($rows),
            'weight_comparison' => [
                'plain_kg' => self::scaleKg($plain['weight_kg']),
                'printed_kg' => self::scaleKg($printed['weight_kg']),
                'total_kg' => self::scaleKg($weight),
                // «كم نسبة المطبوع؟» — the question the whole comparison exists to answer, given
                // once here rather than left to a reader dividing two figures on a phone.
                'printed_share_percent' => self::percent($printed['weight_kg'], $weight, self::KG_SCALE),
                'weight_coverage_percent' => self::percent($valueWithWeight, $value, Money::SCALE),
            ],
            // **A count, kept apart from the weight beside it, because it is the figure the
            // press's own share will one day be computed from.** Plain bags are absent from it by
            // construction: they are summed out of the `none` bucket, never this one.
            'printed_pieces' => [
                'count' => $printed['pieces'],
                'weight_kg' => self::scaleKg($printed['weight_kg']),
            ],
            'orders_counted' => $this->countOrders($filters),
        ];
    }

    /**
     * Which orders this board is built from, and what dates them — **the one place either is
     * said**.
     *
     * **Deliberately identical to the profit & loss statement's own basis.** Two reports read in
     * the same week about the same month must agree on which orders those were; the day they
     * differ, the difference is found by an owner rather than by a test.
     *
     * **And deliberately easy to move.** «متى بيعت؟» has a second sensible answer — «يوم خرجت من
     * المطبعة», `ready_at` — and it is the answer the printing engineer's share will want, since
     * the press has done its work whether or not the customer has taken delivery yet. Changing
     * this board to that basis is these two lines and nothing else, which is the whole reason
     * they are in a method rather than inline in two queries.
     */
    private function recognized(SalesStatisticsFilters $filters): callable
    {
        // Both builders, because both callers are real: the breakdown below is a query builder
        // and the order count is an Eloquent one, and a closure shared by the two has to accept
        // whichever it is handed.
        return function (EloquentBuilder|QueryBuilder $query) use ($filters): void {
            $query->whereIn('orders.status', [OrderStatus::Delivered->value, OrderStatus::Settled->value])
                ->whereBetween(
                    DB::raw('COALESCE(orders.delivered_at, orders.settled_at)'),
                    [$filters->from, $filters->to],
                );
        };
    }

    /**
     * One row per (type, سادة|مطبوع) — everything the board prints, before anything is added up.
     *
     * **A query builder rather than the model**, unlike most reads in this codebase: nothing here
     * is an `OrderItem`, and hydrating one per group only to read six aggregate aliases off it
     * would be a model standing in for a row. The price is that soft deletes must be stated
     * rather than scoped, which is why `deleted_at` is named twice below.
     *
     * @return Collection<int, \stdClass>
     */
    private function breakdown(SalesStatisticsFilters $filters): Collection
    {
        $mode = self::MODE_SQL;
        $type = self::TYPE_SQL;

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('product_categories as cat', 'cat.id', '=', 'products.product_category_id')
            ->leftJoin('product_categories as parent_cat', 'parent_cat.id', '=', 'cat.parent_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('stock_items', 'stock_items.id', '=', 'product_variants.stock_item_id')
            ->leftJoin('stock_item_groups', 'stock_item_groups.id', '=', 'stock_items.stock_item_group_id')
            // A line removed from an order is not a sale, and neither is an order deleted after
            // the fact. Joined tables bring no scope of their own — see the note above.
            ->whereNull('order_items.deleted_at')
            ->whereNull('orders.deleted_at')
            ->where($this->recognized($filters))
            ->whereIn(DB::raw($mode), self::BAG_MODES)
            ->groupByRaw("{$type}, {$mode}")
            ->selectRaw(
                "{$type} AS type_label, ".
                "{$mode} AS production_mode, ".
                'COALESCE(SUM(order_items.line_total), 0) AS value, '.
                // Null where the shelf is not counted in kilograms — summed as nothing, and
                // owned up to through `value_with_weight` rather than passed off as zero.
                'COALESCE(SUM('.self::WEIGHT_SQL.'), 0) AS weight_kg, '.
                'COALESCE(SUM(CASE WHEN '.self::WEIGHT_SQL.' IS NOT NULL THEN order_items.line_total ELSE 0 END), 0) AS value_with_weight, '.
                // **Only what is actually sold by the piece.** A bag priced by the kilo has no
                // piece count to give, and inventing one out of a weight is the conversion
                // COST-TRACKING-UNIT-CONVERSION.md §4 refuses to make.
                "COALESCE(SUM(CASE WHEN order_items.pricing_unit = 'piece' THEN ".self::BILLABLE_SQL.' ELSE 0 END), 0) AS pieces',
            )
            ->get();
    }

    /**
     * How many orders are behind the figures — «على كم طلبية؟», the sanity check a reader makes
     * before trusting any of it.
     *
     * Counted over orders rather than lines, and only orders carrying at least one bag: an order
     * of nothing but ستيكرات contributed nothing above and would inflate this if it counted.
     */
    private function countOrders(SalesStatisticsFilters $filters): int
    {
        return Order::query()
            ->where($this->recognized($filters))
            ->whereExists(function (EloquentBuilder|QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('order_items')
                    ->join('products', 'products.id', '=', 'order_items.product_id')
                    ->leftJoin('product_categories as cat', 'cat.id', '=', 'products.product_category_id')
                    ->leftJoin('product_categories as parent_cat', 'parent_cat.id', '=', 'cat.parent_id')
                    ->whereColumn('order_items.order_id', 'orders.id')
                    ->whereNull('order_items.deleted_at')
                    ->whereIn(DB::raw(self::MODE_SQL), self::BAG_MODES);
            })
            ->count();
    }

    /**
     * Every row of one kind, added up.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array{value: string, weight_kg: string, value_with_weight: string, pieces: int}
     */
    private function fold(Collection $rows, ProductionMode $mode): array
    {
        $matching = $rows->where('production_mode', $mode->value);

        return [
            'value' => $matching->reduce(
                static fn (string $carry, \stdClass $row): string => Money::sum($carry, (string) $row->value),
                '0',
            ),
            'weight_kg' => $matching->reduce(
                static fn (string $carry, \stdClass $row): string => self::addKg($carry, (string) $row->weight_kg),
                '0',
            ),
            'value_with_weight' => $matching->reduce(
                static fn (string $carry, \stdClass $row): string => Money::sum($carry, (string) $row->value_with_weight),
                '0',
            ),
            // A piece is a bag; there is no such thing as two thirds of one, and
            // `PricingUnit::requiresWholeQuantities()` is what stops one being ordered.
            'pieces' => $matching->reduce(
                static fn (int $carry, \stdClass $row): int => $carry + (int) round((float) $row->pieces),
                0,
            ),
        ];
    }

    /**
     * «مبيعات الأكياس حسب النوع» — one row per material, each carrying its own سادة/مطبوع split.
     *
     * **The type is the shelf's material, not the product's heading.** «أكياس شحن - مطبوعة» and
     * «أكياس شحن - سادة» are two catalogue rows drawing on one pile, and a table listing them
     * apart would answer «كم منتجاً بعنا؟» under a heading promising «كم نوعاً». Resolved down a
     * chain — the group, then the shelf, then the name the invoice kept — so a size with no
     * material still lands somewhere rather than falling out of a table that has to add up.
     *
     * Heaviest first, and by value where two weigh the same, so the row a reader came for is at
     * the top.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function byType(Collection $rows): array
    {
        $types = [];

        foreach ($rows as $row) {
            $label = (string) $row->type_label;
            $printed = $row->production_mode === ProductionMode::InHouse->value;

            $types[$label] ??= [
                'type' => $label,
                'value' => '0',
                'weight_kg' => '0',
                'plain_kg' => '0',
                'printed_kg' => '0',
                'pieces' => 0,
            ];

            $types[$label]['value'] = Money::sum($types[$label]['value'], (string) $row->value);
            $types[$label]['weight_kg'] = self::addKg($types[$label]['weight_kg'], (string) $row->weight_kg);

            $bucket = $printed ? 'printed_kg' : 'plain_kg';
            $types[$label][$bucket] = self::addKg($types[$label][$bucket], (string) $row->weight_kg);

            if ($printed) {
                $types[$label]['pieces'] += (int) round((float) $row->pieces);
            }
        }

        $types = array_values($types);

        usort($types, static function (array $a, array $b): int {
            $byWeight = bccomp($b['weight_kg'], $a['weight_kg'], self::KG_SCALE);

            return $byWeight !== 0 ? $byWeight : bccomp($b['value'], $a['value'], Money::SCALE);
        });

        return array_map(static fn (array $type): array => [
            'type' => $type['type'],
            'value' => Money::round($type['value']),
            'weight_kg' => self::scaleKg($type['weight_kg']),
            'plain_kg' => self::scaleKg($type['plain_kg']),
            'printed_kg' => self::scaleKg($type['printed_kg']),
            'pieces' => $type['pieces'],
        ], $types);
    }

    private static function addKg(string $a, string $b): string
    {
        return bcadd($a, $b, self::KG_SCALE);
    }

    /** A weight at the three places every quantity column it came from carries. */
    private static function scaleKg(string $value): string
    {
        return bcadd($value, '0', self::KG_SCALE);
    }

    /**
     * One share of another, to one decimal place — and «0.0» rather than a division by zero for a
     * period in which nothing was sold, which is a real period and not an error.
     */
    private static function percent(string $part, string $whole, int $scale): string
    {
        if (bccomp($whole, '0', $scale) <= 0) {
            return '0.0';
        }

        return bcdiv(bcmul($part, '100', $scale + 2), $whole, 1);
    }
}
