<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Investor\DTOs\WalletEntryData;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\InvestorService;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Actions\DeleteOrder;
use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Actions\RecordScrapLoss;
use App\Domain\Order\Actions\ResolveOrderFlow;
use App\Domain\Order\Actions\RestoreOrder;
use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\ManufacturingCostRate;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\Order\Queries\StockPurchaseAttributionQuery;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Investors\PlainStockPurchaseTest;
use Tests\TestCase;

/**
 * What a restore may and may not re-write — the cost ledger, the scrap, and the investor.
 *
 * `RestoreOrder` undoes a delete **and nothing more**. Everything asserted here is one sentence
 * said four ways:
 *
 * > a round trip through the archive leaves the books exactly where it found them — it may not
 * > invent a cost the press never incurred, nor erase one it did, nor take an investor's goods
 * > without paying him, nor unpay him for a sale that has already completed.
 *
 * The four defects it pins, all of them found in review after the feature was built:
 *
 * 1. **Labour and overhead, costed twice.** `ChangeOrderStatus` costs production once, on the
 *    first arrival at «جاهزة» (`ready_at === null`). A restore that costs unconditionally puts
 *    entries on an order deleted at «جاهزة للطباعة» — where the press has not run at all — and
 *    the move to «جاهزة» that follows writes them again.
 * 2. **The re-drawn stock nobody paid for.** The fresh draw eats today's layers, an investor's
 *    among them, and dispatching nothing takes his goods for free.
 * 3. **The settled purchase, re-attributed.** The delete handed the priced layers to the company
 *    — «المطبعة تتحمّل» — so a stamp kept from before the delete points the ledger at a deal
 *    whose layers are gone.
 * 4. **Scrap, erased.** The reversal voids every entry on the line, `ScrapLoss` included, while
 *    the spoiled bags stay off the shelf.
 *
 * Driven through the domain actions where the question is the domain's, and through the API
 * where the arrangement is a deal's — see {@see PlainStockPurchaseTest},
 * whose shipment this file borrows so both read the same figures.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderRestoreCostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    // ───────────────────────── the plain arrangement ─────────────────────────

    private function actor(): User
    {
        return User::factory()->create();
    }

    /** Puts `$quantity` of `$variant` on `$warehouse`'s shelf at `$unitCost` each. */
    private function stockUp(Warehouse $warehouse, ProductVariant $variant, string $quantity, string $unitCost, User $actor): void
    {
        app(InventoryService::class)->recordMovement(StockMovementData::arrival([
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->getKey(),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ], (int) $actor->getKey()));
    }

    private function balanceOf(Warehouse $warehouse, ProductVariant $variant): string
    {
        return (string) (WarehouseStock::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_item_id', $variant->stock_item_id)
            ->first()?->quantity ?? '0.000');
    }

    /**
     * An order of 300 whose stock has left `$warehouse` — walked through the state machine, so
     * every column and cost layer the delete reads is the real one.
     *
     * @return array{Order, OrderItem}
     */
    private function deductedOrder(Warehouse $warehouse, ProductVariant $variant, User $actor, bool $throughToReady = false): array
    {
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->getKey(),
            'quantity' => '300.000',
        ]);

        app(ChangeOrderStatus::class)(
            $order->refresh(),
            OrderStatus::ReadyToPrint,
            null,
            $actor,
            ['warehouse_id' => $warehouse->getKey()],
        );

        if ($throughToReady) {
            app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Printing, null, $actor);
            app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Ready, null, $actor);
        }

        return [$order->refresh(), $item->refresh()];
    }

    /** Every entry on this line that is neither a reversal nor undone by one. */
    private function activeEntries(OrderItem $item): Collection
    {
        return ProductionCostEntry::query()
            ->where('order_item_id', $item->getKey())
            ->whereNull('reverses_entry_id')
            ->whereDoesntHave('reversal')
            ->get();
    }

    // ───────────────────────── ١ — the press that never ran ─────────────────────────

    public function test_restoring_an_order_the_press_never_ran_brings_it_back_uncosted(): void
    {
        // Arrange — 300 drawn at «جاهزة للطباعة» and deleted there. The press has not touched it:
        // `ready_at` is null and its cost ledger is empty, whatever rates the shop has on file.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        ManufacturingCostRate::factory()->forProduct($product)
            ->type(ManufacturingCostType::Labor)->create(['rate_per_unit' => '0.100']);

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor);

        $this->assertNull($order->ready_at);
        $this->assertCount(0, $this->activeEntries($item));

        app(DeleteOrder::class)($order->refresh(), $actor);

        // Act
        $restored = app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert — a restore undoes the delete and nothing more. The delete voided no production
        // entry, so the restore writes none: the order comes back with its material cost alone.
        $this->assertCount(0, $this->activeEntries($item));
        $this->assertNull($item->refresh()->labor_cost);
        $this->assertNull($item->overhead_cost);
        $this->assertSame('1500.00', (string) $item->cogs);
        $this->assertSame('1500.00', (string) $restored->total_cogs);
    }

    public function test_the_run_is_costed_once_after_a_round_trip_through_the_archive(): void
    {
        // Arrange — the same order, restored, and now actually printed
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        ManufacturingCostRate::factory()->forProduct($product)
            ->type(ManufacturingCostType::Labor)->create(['rate_per_unit' => '0.100']);

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor);

        app(DeleteOrder::class)($order->refresh(), $actor);
        app(RestoreOrder::class)($order->refresh(), $actor);

        // Act — the press runs, for the first and only time
        app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Printing, null, $actor);
        app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Ready, null, $actor);

        // Assert — 300 × 0.100 = 30.00, charged once. A restore that had costed the order on its
        // way out of the archive would leave two live labour entries here and 60.00 on the line,
        // with `cogs` and `total_cogs` doubled underneath them.
        $active = $this->activeEntries($item->refresh());

        $this->assertCount(1, $active);
        $this->assertSame(ManufacturingCostType::Labor, $active->first()->cost_type);
        $this->assertSame('30.00', (string) $item->refresh()->labor_cost);
        $this->assertSame('1530.00', (string) $item->cogs);
        $this->assertSame('1530.00', (string) $order->refresh()->total_cogs);
    }

    public function test_restoring_an_order_the_press_did_run_re_applies_what_the_delete_voided(): void
    {
        // Arrange — the mirror, and the reason the guard is a condition rather than a deletion:
        // an order deleted at «جاهزة» *had* its labour voided by the delete, and comes back
        // owing it again — at today's rate, since the rate is what the shop charges now.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $rate = ManufacturingCostRate::factory()->forProduct($product)
            ->type(ManufacturingCostType::Labor)->create(['rate_per_unit' => '0.100']);

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor, throughToReady: true);

        app(DeleteOrder::class)($order->refresh(), $actor);

        $rate->forceFill(['rate_per_unit' => '0.200'])->save();

        // Act
        $restored = app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert
        $this->assertCount(1, $this->activeEntries($item->refresh()));
        $this->assertSame('60.00', (string) $item->refresh()->labor_cost);
        $this->assertSame('1560.00', (string) $restored->total_cogs);
    }

    // ───────────────────────── ٤ — the bags nobody is charged for ─────────────────────────

    public function test_a_round_trip_conserves_the_scrap_loss(): void
    {
        // Arrange — 300 drawn, then 10 spoiled at the press: 50.00 off the shelf for ever, on
        // its own row in the cost ledger.
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->getKey()]);
        $warehouse = Warehouse::factory()->create();
        $actor = $this->actor();

        $this->stockUp($warehouse, $variant, '500', '5', $actor);
        [$order, $item] = $this->deductedOrder($warehouse, $variant, $actor);

        app(RecordScrapLoss::class)($order->refresh(), $item, '10.000', 'طبعة تالفة', (int) $actor->getKey());

        $this->assertSame('190.000', $this->balanceOf($warehouse, $variant));

        app(DeleteOrder::class)($order->refresh(), $actor);

        // Act
        app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert — the spoiled bags never came back (the delete reverses the fulfilment draw, not
        // the scrap one), so the loss must not come back either. A round trip that erased it
        // would leave the warehouse 10 short and nobody charged for them.
        $scrap = $this->activeEntries($item->refresh())
            ->where('cost_type', ManufacturingCostType::ScrapLoss);

        $this->assertCount(1, $scrap);
        $this->assertSame('50.00', (string) $scrap->first()->amount);
        $this->assertSame('10.000', (string) $scrap->first()->quantity);
        $this->assertSame('طبعة تالفة', $scrap->first()->notes);
        $this->assertSame('190.000', $this->balanceOf($warehouse, $variant));
    }

    // ───────────────────────── the deal ─────────────────────────

    /** @return array<string, string> */
    private function partner(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInvestors->value,
            PermissionName::ManageInvestors->value,
            PermissionName::RecordInvestorMoney->value,
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
            PermissionName::ViewPurchaseOrders->value,
            PermissionName::ManagePurchaseOrders->value,
        ]);

        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** @return array<string, string> */
    private function foreman(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::ManageOrders->value,
            PermissionName::MoveOrderToReadyToPrint->value,
            PermissionName::MoveOrderToPrinting->value,
            PermissionName::MoveOrderToReady->value,
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
        ]);

        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function investorHolding(string $amount): Investor
    {
        $investor = Investor::factory()->create();

        app(InvestorService::class)->recordWalletEntry(
            new WalletEntryData(
                investorId: (int) $investor->getKey(),
                type: WalletEntryType::Deposit,
                amount: $amount,
                method: 'cash',
            ),
            null,
        );

        return $investor;
    }

    /** A printed size weighed by the kilo, on a shelf weighed by the kilo. */
    private function bagSize(): ProductVariant
    {
        $product = Product::factory()->create([
            'pricing_unit' => PricingUnit::Kilogram,
            'product_category_id' => ProductCategory::factory()->investable()->create()->getKey(),
            'is_active' => true,
        ]);

        return ProductVariant::factory()->for($product)->create([
            'label' => '25*35',
            'stock_item_id' => StockItem::factory()->unit(PricingUnit::Kilogram)->create()->getKey(),
        ]);
    }

    private function purchaseOrder(ProductVariant $size, Warehouse $warehouse, string $kilos, string $totalCost): PurchaseOrder
    {
        $order = PurchaseOrder::factory()->create([
            'warehouse_id' => $warehouse->getKey(),
            'status' => PurchaseOrderStatus::New,
        ]);

        PurchaseOrderItem::factory()->forOrder($order)->create([
            'stock_item_id' => $size->stock_item_id,
            'quantity_ordered' => $kilos,
            'base_total_cost' => $totalCost,
            'base_unit_cost' => '25.000',
            'allocated_additional_cost' => '0.00',
            'final_unit_cost' => '25.000',
            'final_total_cost' => $totalCost,
        ]);

        return $order->refresh();
    }

    /**
     * 500 kg at 25.000 landed, funded 1,000 + 1,000 out of the 12,500, and sold to the press at
     * 32.000 — the same shipment {@see PlainStockPurchaseTest} runs on,
     * down to the 16% the partners end up owning.
     *
     * **Not parameterised by size**, deliberately: the partners' cut starts from
     * `investor_funded_percent`, and 1,000 is the floor a deal accepts from one investor — so a
     * smaller lorry funded by the same two people owns a larger share of it and no two tests
     * here would compare.
     *
     * @return array{0: InvestorDeal, 1: ProductVariant, 2: Warehouse}
     */
    private function shipment(array $headers): array
    {
        $warehouse = Warehouse::factory()->create();
        $size = $this->bagSize();
        $order = $this->purchaseOrder($size, $warehouse, '500.000', '12500.00');
        $partners = [$this->investorHolding('5000.00'), $this->investorHolding('5000.00')];

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investor_profit_share_percent' => 50,
                'printing_sale_price' => '32.000',
                'investors' => array_map(
                    fn (Investor $partner) => ['investor_id' => $partner->getKey(), 'amount' => '1000.00'],
                    $partners,
                ),
            ],
        )->assertCreated();

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/arrivals",
            ['items' => [['stock_item_id' => $size->stock_item_id, 'quantity' => '500.000']]],
        )->assertCreated();

        return [
            InvestorDeal::query()->where('purchase_order_id', $order->id)->firstOrFail(),
            $size,
            $warehouse,
        ];
    }

    /** A printed order for `$kilos` of one size, with no extras to muddy the figures. */
    private function sale(ProductVariant $size, string $kilos): Order
    {
        $order = Order::factory()->create([
            'design_fee' => '0.00',
            'delivery_price' => '0.00',
            'discount' => '0.00',
            'additional_cost' => '0.00',
        ]);

        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => $kilos,
            'unit_price' => '60.000',
            'line_total' => bcmul($kilos, '60.000', 2),
            'pricing_unit' => PricingUnit::Kilogram,
        ]);

        app(RecalculateOrderTotals::class)($order->refresh());
        app(ResolveOrderFlow::class)($order->refresh());

        return $order->refresh();
    }

    /** The move that takes the stock off the shelf on the printing road. */
    private function toThePress(array $headers, Order $order, Warehouse $warehouse): TestResponse
    {
        return $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            ['status' => OrderStatus::ReadyToPrint->value, 'fields' => ['warehouse_id' => $warehouse->getKey()]],
        )->assertOk();
    }

    /** What this deal has been paid and not had reversed — the owner reads it down a column. */
    private function paid(InvestorDeal $deal): string
    {
        return (string) InvestorWalletEntry::query()
            ->where('investor_deal_id', $deal->getKey())
            ->where('type', WalletEntryType::Profit->value)
            ->whereDoesntHave('reversedBy')
            ->sum('amount');
    }

    // ───────────────────────── ٢ — the goods the restore takes again ─────────────────────────

    public function test_the_restore_pays_the_deal_whose_layers_the_fresh_draw_eats(): void
    {
        // Arrange — 500 kg at 25 landed, sold to the press at 32, and a printed order for 300.
        // The first draw buys 300 of them: 300 × 7 = 2,100, of which the partners own 16% and
        // keep half of it: 168.
        $headers = $this->partner();
        [$deal, $size, $warehouse] = $this->shipment($headers);
        $order = $this->sale($size, '300');
        $actor = $this->actor();

        $this->toThePress($this->foreman(), $order, $warehouse);

        $this->assertSame('168.00', $this->paid($deal));

        // The delete hands those 300 back as the *company's* own stock at what it paid — the
        // deal keeps its money and its remaining 200 priced kilos.
        app(DeleteOrder::class)($order->refresh(), $actor);

        // Act — the fresh draw is FIFO: the deal's own 200 first, then 100 of the returned lot.
        app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert — a second sale of 200 kg: 200 × 7 × 16% × 50% = 112.00, standing *beside* the
        // first payment rather than replacing it. The deal shipped 500 kilos and has now sold
        // all 500: 168 + 112 = 280.00. A restore that dispatched nothing would leave this at
        // 168.00 with 200 of his kilos in the press and unpaid for.
        $this->assertSame('280.00', $this->paid($deal));
    }

    // ───────────────────────── ٣ — the sale that already completed ─────────────────────────

    public function test_the_settled_purchase_is_not_re_attributed_to_a_deal_whose_layers_are_gone(): void
    {
        // Arrange — an order for the whole lorry: all 500 priced kilos leave in one draw and are
        // paid for, 500 × 7 = 3,500, partners' 16% of it halved = 280.00. The deal has no priced
        // layer left.
        $headers = $this->partner();
        [$deal, $size, $warehouse] = $this->shipment($headers);
        $order = $this->sale($size, '500');
        $actor = $this->actor();

        $this->toThePress($this->foreman(), $order, $warehouse);

        $this->assertSame('280.00', $this->paid($deal));
        $this->assertNotNull($order->items()->firstOrFail()->stock_purchased_at);

        app(DeleteOrder::class)($order->refresh(), $actor);

        // Act — the fresh draw can only reach the company's own returned lot: no priced layer,
        // no deal, no purchase.
        app(RestoreOrder::class)($order->refresh(), $actor);

        // Assert — the line says what *this* draw did, which is «bought from nobody». Keeping
        // the stamp from before the delete would leave the line credited to a deal whose layers
        // the delete already handed to the company, and the next posting keyed on that line
        // would read the first payment as a figure to correct and reverse it — the investor
        // losing the goods and the money both.
        $line = $order->items()->firstOrFail();

        $this->assertNull($line->stock_purchased_at);
        $this->assertSame([], app(StockPurchaseAttributionQuery::class)((int) $order->getKey()));

        // And what he was paid for the sale that did complete stands untouched: «استلم الزبون ما
        // استلمش، المطبعة تتحمّل».
        $this->assertSame('280.00', $this->paid($deal));
    }
}
