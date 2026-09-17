<?php

declare(strict_types=1);

namespace Tests\Feature\Investors;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\DTOs\StockMovementData;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Investor\DTOs\WalletEntryData;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\InvestorService;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealItem;
use App\Domain\Investor\Models\InvestorDealShare;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Investor\Queries\InvestorBalances;
use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A deal buys stock, an order sells it, and the investor is paid — end to end, through the API.
 *
 * The whole feature in one path. Every figure below is arithmetic somebody can check by hand,
 * which is the point: an investor's share is the one number in this system that will be added up
 * on paper by a person who did not write it.
 *
 * Arrange - Act - Assert throughout.
 */
class InvestorDealEarningsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * @return array<string, string>
     */
    private function foreman(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::ManageOrders->value,
            PermissionName::MoveOrderToReadyToPrint->value,
            PermissionName::MoveOrderToPrinting->value,
            PermissionName::MoveOrderToReady->value,
            PermissionName::DispatchOrders->value,
            PermissionName::MarkOrdersDelivered->value,
            PermissionName::SettleOrders->value,
            PermissionName::CancelOrders->value,
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    private function partner(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInvestors->value,
            PermissionName::ManageInvestors->value,
        ]);

        // **The guard caches the first user a test resolves, and the partner is always the
        // second.** A test that walks an order to the customer as the storeman and then reads
        // the deal as the partner is authorised as the storeman for the second request, and
        // fails on a permission the partner plainly has. Forgetting the guards makes the next
        // request resolve its own token — nothing to do with this feature, everything to do
        // with two people in one test.
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** A shelf standing behind one product, under a heading the business invests in. */
    private function investableSize(): ProductVariant
    {
        $category = ProductCategory::factory()->create(['is_investable' => true]);

        $product = Product::factory()->create([
            'pricing_unit' => PricingUnit::Piece,
            'product_category_id' => $category->getKey(),
            'is_active' => true,
        ]);

        return ProductVariant::factory()->for($product)->create([
            'label' => '25*35',
            'stock_item_id' => StockItem::factory()->unit(PricingUnit::Piece)->create()->getKey(),
        ]);
    }

    /**
     * A deal, open, with one investor holding all of it and 30,000 of his money in it.
     *
     * @return array{0: InvestorDeal, 1: Investor}
     */
    private function fundedDeal(ProductVariant $size, string $sharePercent = '100.0000'): array
    {
        $investor = Investor::factory()->create();
        $deal = InvestorDeal::factory()->open()->create(['investor_profit_share_percent' => '50.00']);

        InvestorDealItem::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'stock_item_id' => $size->stock_item_id,
        ]);

        InvestorDealShare::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'investor_id' => $investor->getKey(),
            'share_percent' => $sharePercent,
            'committed_amount' => '30000.00',
        ]);

        return [$deal, $investor];
    }

    /**
     * Stock arriving on a shelf, financed by [$deal] when one is named.
     *
     * **Posted through the real arrival path**, not built with a factory. `WarehouseStockFactory`
     * opens an opening-balance layer at cost zero dated 1970-01-01 — faithfully, because that is
     * what the backfill did to every shelf that existed before batch costing — and FIFO drains
     * those first. A test that arranged its balance that way would sell the deal's goods at a
     * cost of nothing and measure a profit that does not exist.
     */
    private function layer(
        ProductVariant $size,
        Warehouse $warehouse,
        string $quantity,
        string $unitCost,
        ?InvestorDeal $deal = null,
    ): StockBatch {
        app(InventoryService::class)->recordMovement(
            StockMovementData::arrival([
                'stock_item_id' => $size->stock_item_id,
                'to_warehouse_id' => $warehouse->getKey(),
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                // What `ReceivePurchaseOrder` stamps after asking Investment which deal claimed
                // this line — the one place the answer enters the ledger.
                'investor_deal_id' => $deal?->getKey(),
            ], (int) (User::query()->first() ?? User::factory()->create())->getKey()),
        );

        return StockBatch::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function move(array $headers, Order $order, OrderStatus $to, array $fields = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            array_filter(['status' => $to->value, 'fields' => $fields ?: null]),
        );
    }

    /** Walk an order all the way to the customer's hands. */
    private function deliver(array $headers, Order $order, Warehouse $warehouse): void
    {
        $this->move($headers, $order, OrderStatus::ReadyToPrint, ['warehouse_id' => $warehouse->getKey()])->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Printing)->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Ready)->assertOk();
        $carrier = ShippingCompany::factory()->create();
        $this->move($headers, $order->refresh(), OrderStatus::OutForDelivery, [
            'shipping_company_id' => $carrier->getKey(),
        ])->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Delivered)->assertOk();
    }

    // ─────────────────────────── the whole path ───────────────────────────

    public function test_an_investor_earns_his_half_of_what_his_stock_sold_for(): void
    {
        // Arrange — 1,000 units at 2.000 financed by the deal, sold at 5.000 apiece.
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal, $investor] = $this->fundedDeal($size);
        $this->layer($size, $warehouse, '1000', '2.000', $deal);

        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '1000',
            'unit_price' => '5.000',
            'line_total' => '5000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($order->refresh());

        // Act
        $this->deliver($this->foreman(), $order->refresh(), $warehouse);

        // Assert — revenue 5,000.00, cost 2,000.00, so the order made 3,000.00. Half of it is
        // the investors' by this deal's terms, and this investor holds all of that half.
        $entry = InvestorWalletEntry::query()
            ->where('investor_id', $investor->getKey())
            ->where('type', WalletEntryType::Profit->value)
            ->sole();

        $this->assertSame('1500.00', (string) $entry->amount);
        $this->assertSame((int) $order->getKey(), (int) $entry->source_id);

        // And it is profit, not capital: his wallet's two pots stay apart.
        $balances = app(InvestorBalances::class)->forInvestor((int) $investor->getKey());
        $this->assertSame('1500.00', $balances['deals'][$deal->getKey()]['profit']);
        $this->assertSame('0.00', $balances['wallet']['profit']);
    }

    public function test_the_ledger_says_whose_goods_went_out_of_a_shared_shelf(): void
    {
        // Arrange — the same shared pile the split is computed from: 2,000 of ours (older) and
        // 6,000 the deal financed. A line for 3,000 takes all of ours and 1,000 of theirs.
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal] = $this->fundedDeal($size);

        $company = $this->layer($size, $warehouse, '2000', '1.800');
        $company->forceFill(['received_at' => now()->subDays(10)])->save();

        $funded = $this->layer($size, $warehouse, '6000', '2.000', $deal);
        $funded->forceFill(['received_at' => now()->subDay()])->save();

        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '3000',
            'unit_price' => '5.000',
            'line_total' => '15000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($order->refresh());

        $headers = $this->foreman();
        $this->move($headers, $order->refresh(), OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
        ])->assertOk();

        // Act — the shelf's own ledger, which showed «−3,000» and nothing about whose.
        $response = $this->withHeaders($headers)->getJson(
            '/api/v1/stock-movements?stock_item_id='.$size->stock_item_id.'&warehouse_id='.$warehouse->getKey(),
        );

        // Assert — one line per owner: 2,000 the company's, 1,000 the deal's. The split has been
        // in `stock_batch_consumptions` since the first sale; this is the first screen to say it.
        $response->assertOk();

        $draws = collect($response->json('data.0.investor_draws'))->keyBy('investor_deal_id');

        $this->assertSame('2000.000', $draws[null]['quantity']);
        $this->assertNull($draws[null]['code']);
        $this->assertSame('1000.000', $draws[$deal->getKey()]['quantity']);
        $this->assertSame($deal->code, $draws[$deal->getKey()]['code']);
    }

    public function test_a_deal_is_paid_only_for_the_units_it_actually_financed(): void
    {
        // Arrange — the line draws 2,000 of the company's own stock first (it is older) and then
        // 1,000 of the deal's. This is the case a proportional split gets wrong.
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal, $investor] = $this->fundedDeal($size);

        $company = $this->layer($size, $warehouse, '2000', '1.800');
        $company->forceFill(['received_at' => now()->subDays(10)])->save();

        $funded = $this->layer($size, $warehouse, '6000', '2.000', $deal);
        $funded->forceFill(['received_at' => now()->subDay()])->save();

        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '3000',
            'unit_price' => '5.000',
            'line_total' => '15000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($order->refresh());

        // Act
        $this->deliver($this->foreman(), $order->refresh(), $warehouse);

        // Assert — the deal's third of the line is 5,000.00 of revenue against 2,000.00 of its
        // own cost, so it made 3,000.00 and the investors' half is 1,500.00.
        //
        // **Allocating across the deal's own draw alone would have credited it the whole
        // 15,000.00** — a largest-remainder split hands its total to whatever weights it is
        // given, and one weight takes all of it. That is a threefold overpayment that no
        // quantity or cost invariant in this system would notice.
        $entry = InvestorWalletEntry::query()
            ->where('investor_id', $investor->getKey())
            ->where('type', WalletEntryType::Profit->value)
            ->sole();

        $this->assertSame('1500.00', (string) $entry->amount);
    }

    public function test_two_investors_split_their_half_by_their_own_percentages(): void
    {
        // Arrange — 60/40 of the investors' half.
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal, $ahmed] = $this->fundedDeal($size, '60.0000');

        $mohamed = Investor::factory()->create();
        InvestorDealShare::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'investor_id' => $mohamed->getKey(),
            'share_percent' => '40.0000',
            'committed_amount' => '20000.00',
        ]);

        $this->layer($size, $warehouse, '1000', '2.000', $deal);

        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '1000',
            'unit_price' => '5.000',
            'line_total' => '5000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($order->refresh());

        // Act
        $this->deliver($this->foreman(), $order->refresh(), $warehouse);

        // Assert — 1,500.00 between them: 900.00 and 600.00, summing to the pool exactly.
        $amounts = InvestorWalletEntry::query()
            ->where('type', WalletEntryType::Profit->value)
            ->orderBy('id')
            ->pluck('amount', 'investor_id')
            ->map(fn ($amount) => (string) $amount)
            ->all();

        $this->assertSame('900.00', $amounts[$ahmed->getKey()]);
        $this->assertSame('600.00', $amounts[$mohamed->getKey()]);
    }

    public function test_a_sale_that_lost_money_is_shared_the_same_way_a_profit_is(): void
    {
        // Arrange — bought at 6.000, sold at 5.000.
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal, $investor] = $this->fundedDeal($size);
        $this->layer($size, $warehouse, '1000', '6.000', $deal);

        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '1000',
            'unit_price' => '5.000',
            'line_total' => '5000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($order->refresh());

        // Act
        $this->deliver($this->foreman(), $order->refresh(), $warehouse);

        // Assert — 1,000.00 lost on the order, half of it his: «لو خسرنا نتقاسمها زي مانتقاسم
        // الربح». Recorded as its own row type rather than as a negative amount, because every
        // amount in this ledger is positive and the direction lives in the type.
        $entry = InvestorWalletEntry::query()
            ->where('investor_id', $investor->getKey())
            ->where('type', WalletEntryType::Loss->value)
            ->sole();

        $this->assertSame('500.00', (string) $entry->amount);

        $balances = app(InvestorBalances::class)->forInvestor((int) $investor->getKey());
        $this->assertSame('-500.00', $balances['deals'][$deal->getKey()]['profit']);
    }

    public function test_an_order_that_never_reached_the_customer_pays_nobody(): void
    {
        // Arrange
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal] = $this->fundedDeal($size);
        $this->layer($size, $warehouse, '1000', '2.000', $deal);

        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '1000',
            'unit_price' => '5.000',
            'line_total' => '5000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        $headers = $this->foreman();

        // Act — the stock leaves at «جاهزة للطباعة», days before anybody is paid.
        $this->move($headers, $order->refresh(), OrderStatus::ReadyToPrint, ['warehouse_id' => $warehouse->getKey()])
            ->assertOk();

        // Assert — nothing is booked. Until an order is delivered it can still come home and be
        // cancelled, and its profit with it; writing the money early would mean taking it back.
        $this->assertSame(0, InvestorWalletEntry::query()->count());
    }

    public function test_delivering_the_same_order_twice_pays_once(): void
    {
        // Arrange
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal, $investor] = $this->fundedDeal($size);
        $this->layer($size, $warehouse, '1000', '2.000', $deal);

        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '1000',
            'unit_price' => '5.000',
            'line_total' => '5000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($order->refresh());
        $headers = $this->foreman();
        $this->deliver($headers, $order->refresh(), $warehouse);

        // Act — the same finalisation runs again, which is what a settlement after a delivery
        // does and what a retried job would do.
        app(InvestorService::class)->postEarningsForOrder((int) $order->getKey());
        app(InvestorService::class)->postEarningsForOrder((int) $order->getKey());

        // Assert — one row, one payment. The action compares what it would write against what
        // already stands and writes nothing when they agree; the partial unique index behind
        // (investor, deal, source, sequence) is the database's own backstop for the day a caller
        // forgets the lock.
        $this->assertSame(
            1,
            InvestorWalletEntry::query()
                ->where('investor_id', $investor->getKey())
                ->where('type', WalletEntryType::Profit->value)
                ->count(),
        );
    }

    // ─────────────────────────── the paperwork ───────────────────────────

    // ─────────────────────── the orders behind the money ───────────────────────

    public function test_a_deal_lists_the_order_that_sold_its_goods_and_what_it_earned(): void
    {
        // Arrange — the shared shelf again: 2,000 of ours (older) and 6,000 the deal financed,
        // and a line for 3,000 that takes all of ours and 1,000 of theirs.
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal] = $this->fundedDeal($size);

        $company = $this->layer($size, $warehouse, '2000', '1.800');
        $company->forceFill(['received_at' => now()->subDays(10)])->save();

        $funded = $this->layer($size, $warehouse, '6000', '2.000', $deal);
        $funded->forceFill(['received_at' => now()->subDay()])->save();

        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '3000',
            'unit_price' => '5.000',
            'line_total' => '15000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($order->refresh());
        $this->deliver($this->foreman(), $order->refresh(), $warehouse);

        // Act
        $response = $this->withHeaders($this->partner())
            ->getJson("/api/v1/investor-deals/{$deal->id}/orders");

        // Assert — one row, and every figure on it is the arithmetic somebody can check by hand:
        // 1,000 units off this deal at 2.000 apiece, a third of the line's 15,000.00 in revenue,
        // so the deal made 3,000.00 — of which half went to the investors and half stayed here.
        $response->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame((int) $order->getKey(), $response->json('data.0.order_id'));
        $this->assertSame('1000.000', $response->json('data.0.quantity'));
        $this->assertSame('2000.00', $response->json('data.0.material_cost'));
        $this->assertSame('5000.00', $response->json('data.0.revenue'));
        $this->assertSame('3000.00', $response->json('data.0.profit'));
        $this->assertSame('1500.00', $response->json('data.0.investors_share'));
        $this->assertSame('1500.00', $response->json('data.0.company_share'));
        $this->assertTrue($response->json('data.0.is_posted'));

        // And the row says which order it was, in the code staff say out loud.
        $this->assertSame($order->refresh()->code, $response->json('data.0.code'));
    }

    public function test_an_order_still_on_the_road_is_listed_with_nothing_paid_yet(): void
    {
        // Arrange — the goods left the shelf at «جاهزة للطباعة» and the parcel is not there yet.
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal] = $this->fundedDeal($size);
        $this->layer($size, $warehouse, '1000', '2.000', $deal);

        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '1000',
            'unit_price' => '5.000',
            'line_total' => '5000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($order->refresh());

        $this->move($this->foreman(), $order->refresh(), OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
        ])->assertOk();

        // Act
        $response = $this->withHeaders($this->partner())
            ->getJson("/api/v1/investor-deals/{$deal->id}/orders");

        // Assert — it is on the list, because it is holding this deal's stock right now and is
        // exactly what refuses to let the deal close. But nothing has been paid for it: the
        // share is **null** rather than 0.00, which is a different sentence — an order that
        // broke exactly even would say 0.00.
        $response->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame('3000.00', $response->json('data.0.profit'));
        $this->assertNull($response->json('data.0.investors_share'));
        $this->assertNull($response->json('data.0.company_share'));
        $this->assertFalse($response->json('data.0.is_posted'));
    }

    public function test_a_cancelled_order_leaves_the_deal_s_list_with_the_goods_it_gave_back(): void
    {
        // Arrange — the stock left the shelf, then the order was cancelled whole.
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal] = $this->fundedDeal($size);
        $this->layer($size, $warehouse, '1000', '2.000', $deal);

        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '1000',
            'unit_price' => '5.000',
            'line_total' => '5000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($order->refresh());

        $headers = $this->foreman();
        $this->move($headers, $order->refresh(), OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
        ])->assertOk();

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Cancelled->value,
            'reason' => 'الزبون ألغى',
        ])->assertOk();

        // Assert — gone. `CreditBackStockBatches` put the goods back into this deal's own
        // layers, so the order took nothing from it and earned it nothing; leaving the row
        // behind would show a sale that was undone.
        $response = $this->withHeaders($this->partner())
            ->getJson("/api/v1/investor-deals/{$deal->id}/orders");

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_deal_may_not_be_struck_on_a_shelf_a_non_investable_product_also_stands_on(): void
    {
        // Arrange — one shelf, two products: bags the business invests in, and stickers it does
        // not. This is the real shape of the catalogue, not a contrived one. An order brings
        // 20,000 of that shelf, and a partner with money is ready to fund it.
        $size = $this->investableSize();

        $stickers = ProductCategory::factory()->create(['is_investable' => false]);
        $sticker = Product::factory()->create([
            'pricing_unit' => PricingUnit::Piece,
            'product_category_id' => $stickers->getKey(),
            'is_active' => true,
        ]);
        ProductVariant::factory()->for($sticker)->create([
            'label' => '25*35',
            'stock_item_id' => $size->stock_item_id,
        ]);

        $order = PurchaseOrder::factory()->create([
            'warehouse_id' => Warehouse::factory()->create()->getKey(),
            'status' => PurchaseOrderStatus::New,
        ]);
        PurchaseOrderItem::factory()->forOrder($order)->create([
            'stock_item_id' => $size->stock_item_id,
            'quantity_ordered' => '10000.000',
            'base_total_cost' => '20000.00',
            'base_unit_cost' => '2.000',
            'allocated_additional_cost' => '0.00',
            'final_unit_cost' => '2.000',
            'final_total_cost' => '20000.00',
        ]);

        $investor = Investor::factory()->create();
        app(InvestorService::class)->recordWalletEntry(
            new WalletEntryData(
                investorId: (int) $investor->getKey(),
                type: WalletEntryType::Deposit,
                amount: '5000.00',
                method: 'cash',
            ),
            null,
        );

        // Act — through the only door a deal is born through.
        $response = $this->withHeaders($this->partner())->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            ['investors' => [['investor_id' => $investor->getKey(), 'amount' => '3000.00']]],
        );

        // Assert — refused whole, and nothing half-made left behind. FIFO draws from the oldest
        // layer whatever product is on the invoice, so a shelf shared with a sticker would have
        // the investor financing bags and being paid a sticker's margin. The guard is «every
        // product here is investable», not «one of them».
        $response->assertStatus(422);
        $this->assertSame(0, InvestorDeal::query()->count());
        $this->assertSame(0, InvestorWalletEntry::query()->where('type', WalletEntryType::Allocation->value)->count());
    }

    // ───────────────────── what the deal is standing to make ─────────────────────

    /**
     * A helper the two totals tests share: one order for 1,000 bags at 5.000, priced and costed.
     */
    private function saleOf(ProductVariant $size): Order
    {
        $order = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($order)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '1000',
            'unit_price' => '5.000',
            'line_total' => '5000.00',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($order->refresh());

        return $order->refresh();
    }

    public function test_a_deal_adds_up_the_profit_of_the_orders_on_the_road_beside_the_ones_delivered(): void
    {
        // Arrange — one financed shelf and two identical sales off it: 1,000 bags each, bought
        // at 2.000 and sold at 5.000, so each one makes the deal 3,000.00. One reached the
        // customer; the other is still on the road, its goods off the shelf since «جاهزة
        // للطباعة».
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal] = $this->fundedDeal($size);
        $this->layer($size, $warehouse, '6000', '2.000', $deal);

        $headers = $this->foreman();

        $delivered = $this->saleOf($size);
        $this->deliver($headers, $delivered, $warehouse);

        $onTheRoad = $this->saleOf($size);
        $this->move($headers, $onTheRoad, OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
        ])->assertOk();

        // Act
        $response = $this->withHeaders($this->partner())->getJson("/api/v1/investor-deals/{$deal->id}");

        // Assert — the two buckets and their sum. The delivered 3,000.00 is money the deal has
        // made; the 3,000.00 on the road is money it stands to make, and the total is what the
        // deal is worth if every parcel arrives.
        $response->assertOk();

        $this->assertSame(1, $response->json('data.orders_profit.delivered.orders'));
        $this->assertSame('3000.00', $response->json('data.orders_profit.delivered.profit'));

        $this->assertSame(1, $response->json('data.orders_profit.in_flight.orders'));
        $this->assertSame('3000.00', $response->json('data.orders_profit.in_flight.profit'));

        $this->assertSame(2, $response->json('data.orders_profit.total.orders'));
        $this->assertSame('6000.00', $response->json('data.orders_profit.total.profit'));

        // And the ledger still says only what was actually paid — half of the delivered order's
        // profit, and nothing for the parcel that has not arrived.
        $this->assertSame('1500.00', $response->json('data.balances.profit'));
    }

    public function test_a_cancelled_order_is_counted_in_neither_total(): void
    {
        // Arrange — the goods left the shelf and came back: `CreditBackStockBatches` returned
        // them to this deal's own layers.
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        [$deal] = $this->fundedDeal($size);
        $this->layer($size, $warehouse, '6000', '2.000', $deal);

        $headers = $this->foreman();
        $order = $this->saleOf($size);
        $this->move($headers, $order, OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
        ])->assertOk();

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Cancelled->value,
            'reason' => 'الزبون ألغى',
        ])->assertOk();

        // Assert — it took nothing off this deal and earned it nothing, so it is not on the
        // list and not in the totals either. A screen counting it would promise a profit whose
        // goods are back on the shelf, waiting to be sold again.
        $response = $this->withHeaders($this->partner())->getJson("/api/v1/investor-deals/{$deal->id}");

        $response->assertOk();

        $this->assertSame(0, $response->json('data.orders_profit.total.orders'));
        $this->assertSame('0.00', $response->json('data.orders_profit.total.profit'));
        $this->assertSame('0.00', $response->json('data.orders_profit.in_flight.profit'));
    }
}
