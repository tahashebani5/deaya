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
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Investor\DTOs\WalletEntryData;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\InvestorService;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Actions\ResolveOrderFlow;
use App\Domain\Order\Enums\ManufacturingCostType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\ProductionCostEntry;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * سعر السادة — the press buys the investor's plain bags off the shelf.
 *
 * The owner's rule, 2026-09-06, in his words: «الشركة نفسها مطبعة — يعني كأننا بنشروه من
 * المستثمر… أي حاجة تطلع من المخزون الكيلو يمشي بسعر السادة بالوزن، كأنه باعها بيع ليّا…
 * استلم الزبون ما استلمش، المطبعة تتحمّل». And, on how the money divides: «للشركة نسبة فيها،
 * فنسبة فيها أعطيها للشركة بشكل طبيعي وانتهينا، وباقي يتوزع بينهم» — ownership alone, with no
 * half taken off the top for the company's work.
 *
 * **And the other half of the instruction, which is what most of this file guards:** «الساده
 * حتقعد زي ماهي زي كل شيء حاليا، اللي بيختلف فقط لما تكون طلبية طباعة». A plain line sold as it
 * stands walks exactly the road it walked yesterday.
 *
 * One shipment carries every case below:
 *
 * ```
 * أمر شراء   500 كغ بتكلفة 25.000  =  12,500.00
 * تمويل      1,000 + 1,000 = 2,000   → الشركة 10,500 ، والشركاء يملكون 16%
 * سعر السادة 32.000 للكيلو
 * ```
 *
 * Arrange - Act - Assert throughout.
 */
class PlainStockPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    // ─────────────────────────── people ───────────────────────────

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

        // The guard caches the first user a test resolves; a second person in the same test
        // would otherwise be authorised as the first.
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
            PermissionName::DispatchOrders->value,
            PermissionName::MarkOrdersDelivered->value,
            PermissionName::CancelOrders->value,
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

    // ─────────────────────────── goods ───────────────────────────

    /**
     * A size weighed by the kilo, on a shelf weighed by the kilo — so nothing here needs a second
     * measurement and the price per kilo is the price of what leaves.
     */
    private function bagSize(bool $printed): ProductVariant
    {
        $category = ProductCategory::factory()->investable();

        $product = Product::factory()->create([
            'pricing_unit' => PricingUnit::Kilogram,
            'product_category_id' => ($printed ? $category : $category->skipsProduction())->create()->getKey(),
            'is_active' => true,
        ]);

        return ProductVariant::factory()->for($product)->create([
            'label' => '25*35',
            'stock_item_id' => StockItem::factory()->unit(PricingUnit::Kilogram)->create()->getKey(),
        ]);
    }

    /** 500 kg at 25.000 landed — one line, one shelf. */
    private function purchaseOrder(ProductVariant $size, Warehouse $warehouse): PurchaseOrder
    {
        $order = PurchaseOrder::factory()->create([
            'warehouse_id' => $warehouse->getKey(),
            'status' => PurchaseOrderStatus::New,
        ]);

        PurchaseOrderItem::factory()->forOrder($order)->create([
            'stock_item_id' => $size->stock_item_id,
            'quantity_ordered' => '500.000',
            'base_total_cost' => '12500.00',
            'base_unit_cost' => '25.000',
            'allocated_additional_cost' => '0.00',
            'final_unit_cost' => '25.000',
            'final_total_cost' => '12500.00',
        ]);

        return $order->refresh();
    }

    /**
     * The whole arrangement, funded and received: two partners with 1,000 each, and — unless
     * `$printingSalePrice` is null — an agreed سعر السادة.
     *
     * @return array{0: InvestorDeal, 1: list<Investor>, 2: ProductVariant, 3: Warehouse}
     */
    private function shipment(array $headers, ?string $printingSalePrice = '32.000', bool $printed = true): array
    {
        $warehouse = Warehouse::factory()->create();
        $size = $this->bagSize($printed);
        $order = $this->purchaseOrder($size, $warehouse);
        $partners = [$this->investorHolding('5000.00'), $this->investorHolding('5000.00')];

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            array_filter([
                'investor_profit_share_percent' => 50,
                'printing_sale_price' => $printingSalePrice,
                'investors' => array_map(
                    fn (Investor $partner) => ['investor_id' => $partner->getKey(), 'amount' => '1000.00'],
                    $partners,
                ),
            ]),
        )->assertCreated();

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/arrivals",
            ['items' => [['stock_item_id' => $size->stock_item_id, 'quantity' => '500.000']]],
        )->assertCreated();

        return [
            InvestorDeal::query()->where('purchase_order_id', $order->id)->firstOrFail(),
            $partners,
            $size,
            $warehouse,
        ];
    }

    /** An order for `$kilos` of one size at `$unitPrice`, with no extras to muddy the figures. */
    private function sale(ProductVariant $size, string $kilos, string $unitPrice): Order
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
            'unit_price' => $unitPrice,
            'line_total' => bcmul($kilos, $unitPrice, 2),
            'pricing_unit' => PricingUnit::Kilogram,
        ]);

        app(RecalculateOrderTotals::class)($order->refresh());

        // The road is derived from the lines, exactly as intake derives it — a سادة order that
        // skipped this would be offered «جاهزة للطباعة» it has no press for.
        app(ResolveOrderFlow::class)($order->refresh());

        return $order->refresh();
    }

    /** @param array<string, mixed> $fields */
    private function move(array $headers, Order $order, OrderStatus $to, array $fields = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            array_filter(['status' => $to->value, 'fields' => $fields ?: null]),
        );
    }

    /** The move that takes the stock off the shelf on the printing road. */
    private function toTheePress(array $headers, Order $order, Warehouse $warehouse): void
    {
        $this->move($headers, $order, OrderStatus::ReadyToPrint, ['warehouse_id' => $warehouse->getKey()])->assertOk();
    }

    private function deliver(array $headers, Order $order, Warehouse $warehouse, bool $printed = true): void
    {
        if ($printed) {
            $this->toTheePress($headers, $order, $warehouse);
            $this->move($headers, $order->refresh(), OrderStatus::Printing)->assertOk();
            $this->move($headers, $order->refresh(), OrderStatus::Ready)->assertOk();
        } else {
            $this->move($headers, $order, OrderStatus::Ready, ['warehouse_id' => $warehouse->getKey()])->assertOk();
        }

        $carrier = ShippingCompany::factory()->create();
        $this->move($headers, $order->refresh(), OrderStatus::OutForDelivery, [
            'shipping_company_id' => $carrier->getKey(),
        ])->assertOk();
        $this->move($headers, $order->refresh(), OrderStatus::Delivered)->assertOk();
    }

    /** What this deal has paid out, by type — the owner reads it down a column. */
    private function paid(InvestorDeal $deal, WalletEntryType $type): string
    {
        return (string) InvestorWalletEntry::query()
            ->where('investor_deal_id', $deal->getKey())
            ->where('type', $type->value)
            ->whereDoesntHave('reversedBy')
            ->sum('amount');
    }

    // ─────────────────────────── the purchase ───────────────────────────

    public function test_the_press_buys_the_plain_stock_the_moment_it_leaves_the_shelf(): void
    {
        // Arrange — 500 kg at 25, sold to the press at 32, and a printed order for 300 of them.
        $headers = $this->partner();
        [$deal, $partners, $size, $warehouse] = $this->shipment($headers);
        $order = $this->sale($size, '300', '60.000');

        $this->assertSame('16.0000', (string) $deal->investor_funded_percent);
        $this->assertSame('32.000', (string) $deal->printing_sale_price);

        // Act — nothing but the warehouse handing the goods to the press. No delivery, no
        // customer, no invoice paid.
        $this->toTheePress($this->foreman(), $order, $warehouse);

        // Assert — the margin is 300 × (32 − 25) = 2,100, of which the partners own 16% and keep
        // half: 168.00, split equally between the two. Same two factors as the delivered sale;
        // only the moment and the figure they apply to differ on this road.
        $this->assertSame('168.00', $this->paid($deal, WalletEntryType::Profit));

        foreach ($partners as $partner) {
            $this->assertSame('84.00', (string) InvestorWalletEntry::query()
                ->where('investor_deal_id', $deal->getKey())
                ->where('investor_id', $partner->getKey())
                ->where('type', WalletEntryType::Profit->value)
                ->sum('amount'));
        }

        // And the line carries both numbers: what the press paid, and what the goods cost.
        $line = $order->items()->firstOrFail()->refresh();
        $this->assertSame('9600.00', (string) $line->material_cost);
        $this->assertSame('7500.00', (string) $line->material_cost_actual);
        $this->assertNotNull($line->stock_purchased_at);
    }

    public function test_delivering_the_order_does_not_pay_the_investor_a_second_time(): void
    {
        // Arrange — the press has bought, and the parcel now goes all the way to the customer.
        $headers = $this->partner();
        [$deal, , $size, $warehouse] = $this->shipment($headers);
        $order = $this->sale($size, '300', '60.000');

        // Act
        $this->deliver($this->foreman(), $order, $warehouse);

        // Assert — still the one purchase, and nothing added by «تم الاستلام». The sale's own
        // profit is the company's alone: it bought the material and carried the job.
        $this->assertSame('168.00', $this->paid($deal, WalletEntryType::Profit));
        $this->assertSame('0', $this->paid($deal, WalletEntryType::Loss));
    }

    public function test_a_cancelled_order_keeps_the_investors_money_and_hands_the_goods_to_the_company(): void
    {
        // Arrange — the goods are with the press and the investors have been paid.
        $headers = $this->partner();
        [$deal, , $size, $warehouse] = $this->shipment($headers);
        $order = $this->sale($size, '300', '60.000');
        $foreman = $this->foreman();
        $this->toTheePress($foreman, $order, $warehouse);

        // Act — «استلم الزبون ما استلمش، المطبعة تتحمّل».
        $this->withHeaders($foreman)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Cancelled->value,
            'reason' => 'رفض الزبون استلام الطلبية',
        ])->assertOk();

        // Assert — his money is untouched.
        $this->assertSame('168.00', $this->paid($deal, WalletEntryType::Profit));

        // The deal's own layer keeps only what never left: 500 − 300.
        $funded = StockBatch::query()->where('investor_deal_id', $deal->getKey())->firstOrFail();
        $this->assertSame('200.000', (string) $funded->quantity_remaining);

        // And the 300 that came back are the company's, at what the company paid for them.
        $returned = StockBatch::query()
            ->where('stock_item_id', $size->stock_item_id)
            ->whereNull('investor_deal_id')
            ->firstOrFail();

        $this->assertSame('300.000', (string) $returned->quantity_remaining);
        $this->assertSame('32.000', (string) $returned->unit_cost);
        $this->assertNull($returned->printing_sale_price);
    }

    // ─────────────────────────── and what does not change ───────────────────────────

    public function test_a_plain_line_sold_as_it_stands_is_untouched_by_any_of_this(): void
    {
        // Arrange — the same shipment at the same 32, but the goods are sold سادة.
        $headers = $this->partner();
        [$deal, , $size, $warehouse] = $this->shipment($headers, printed: false);
        $order = $this->sale($size, '300', '40.000');

        // Act — the whole road, because on this one the money only lands at the end of it.
        $this->deliver($this->foreman(), $order, $warehouse, printed: false);

        // Assert — the line bought nothing, and its material is what the goods cost.
        $line = $order->items()->firstOrFail()->refresh();
        $this->assertNull($line->stock_purchased_at);
        $this->assertSame('7500.00', (string) $line->material_cost);
        $this->assertSame('7500.00', (string) $line->material_cost_actual);

        // And he is paid the old way: the order made 12,000 − 7,500 = 4,500, of which the
        // partners own 16% and keep half of that — 360.00.
        $this->assertSame('360.00', $this->paid($deal, WalletEntryType::Profit));
    }

    public function test_a_deal_funded_without_a_price_still_rides_the_sale(): void
    {
        // Arrange — a printed order, but nobody agreed a سعر السادة for this lorry.
        $headers = $this->partner();
        [$deal, , $size, $warehouse] = $this->shipment($headers, printingSalePrice: null);
        $order = $this->sale($size, '300', '60.000');

        // Act
        $foreman = $this->foreman();
        $this->toTheePress($foreman, $order, $warehouse);

        // Assert — nothing is bought at the shelf.
        $line = $order->items()->firstOrFail()->refresh();
        $this->assertNull($line->stock_purchased_at);
        $this->assertSame('7500.00', (string) $line->material_cost);
        $this->assertSame('0', $this->paid($deal, WalletEntryType::Profit));

        // Act — and the money lands where it always did.
        $this->move($foreman, $order->refresh(), OrderStatus::Printing)->assertOk();
        $this->move($foreman, $order->refresh(), OrderStatus::Ready)->assertOk();
        $carrier = ShippingCompany::factory()->create();
        $this->move($foreman, $order->refresh(), OrderStatus::OutForDelivery, [
            'shipping_company_id' => $carrier->getKey(),
        ])->assertOk();
        $this->move($foreman, $order->refresh(), OrderStatus::Delivered)->assertOk();

        // Assert — 18,000 − 7,500 = 10,500 × 16% × 50% = 840.00.
        $this->assertSame('840.00', $this->paid($deal, WalletEntryType::Profit));
    }

    public function test_bags_spoiled_at_the_press_are_bought_too(): void
    {
        // Arrange — the run is at the press, and 20 kg of it is ruined.
        $headers = $this->partner();
        [$deal, , $size, $warehouse] = $this->shipment($headers);
        $order = $this->sale($size, '300', '60.000');
        $foreman = $this->foreman();
        $this->toTheePress($foreman, $order, $warehouse);

        $line = $order->items()->firstOrFail();

        // Act — «هي من لما تكون جاهزة وبينخصم من المخزون خلاص اعطيه حقاته».
        $this->withHeaders($foreman)->postJson(
            "/api/v1/orders/{$order->id}/items/{$line->id}/scrap",
            ['quantity' => '20', 'notes' => 'طباعة خرجت غلط'],
        )->assertCreated();

        // Assert — the first draw paid 168.00 on 300 kg; these 20 pay 20 × (32 − 25) × 16% × 50%
        // = 11.20 on top of it, on their own row rather than in place of the first.
        $this->assertSame('179.20', $this->paid($deal, WalletEntryType::Profit));

        // And the press carries the spoilage at what it paid for it — 20 × 32 — not at cost.
        $this->assertSame('640.00', (string) ProductionCostEntry::query()
            ->where('order_item_id', $line->getKey())
            ->where('cost_type', ManufacturingCostType::ScrapLoss->value)
            ->sum('amount'));
    }

    // ─────────────────────────── what the order screen is told ───────────────────────────

    public function test_the_order_says_which_deal_took_money_out_of_it_and_whether_it_was_paid(): void
    {
        // Arrange — a printed order that bought 300 kg off D22 at 32.
        $headers = $this->partner();
        [$deal, , $size, $warehouse] = $this->shipment($headers);
        $order = $this->sale($size, '300', '60.000');
        $this->toTheePress($this->foreman(), $order, $warehouse);

        // Act — the guard still holds the foreman who moved the order; the reader is somebody
        // else entirely.
        $this->app['auth']->forgetGuards();

        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/orders/{$order->id}/investor-shares")
            ->assertOk();

        // Assert — one row, on the buying road, already settled: the press paid 9,600 for the
        // goods, 2,100 of it was margin, the partners' half of their 16% of that is 168, and it
        // is in their ledgers before the parcel has moved.
        $response
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deal_code', $deal->code)
            ->assertJsonPath('data.0.kind', 'plain_sale')
            ->assertJsonPath('data.0.kind_label', 'بيع السادة للمطبعة')
            ->assertJsonPath('data.0.goods_amount', '9600.00')
            ->assertJsonPath('data.0.profit', '2100.00')
            ->assertJsonPath('data.0.investors_share', '168.00')
            ->assertJsonPath('data.0.company_share', '1932.00')
            ->assertJsonPath('data.0.is_paid', true)
            ->assertJsonPath('data.0.paid_amount', '168.00');
    }

    public function test_the_deals_own_order_list_shows_what_the_press_bought(): void
    {
        // Arrange — the press has bought 300 kg off the deal and paid for them.
        $headers = $this->partner();
        [$deal, , $size, $warehouse] = $this->shipment($headers);
        $order = $this->sale($size, '300', '60.000');
        $this->toTheePress($this->foreman(), $order, $warehouse);

        // Act — «طلبيات الصفقة», read from the deal's own end.
        $this->app['auth']->forgetGuards();

        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/investor-deals/{$deal->id}/orders")
            ->assertOk();

        // Assert — the row carries the purchase, not a row of zeros. `OrderDealSlices` leaves
        // this draw out so that delivery cannot pay for it twice, and reading it alone would make
        // 300 kg of the deal's own goods disappear off its statement.
        $response
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', '300.000')
            ->assertJsonPath('data.0.material_cost', '7500.00')
            // What the press paid, not what the customer will: on this road the sale completed
            // at the warehouse door.
            ->assertJsonPath('data.0.revenue', '9600.00')
            ->assertJsonPath('data.0.profit', '2100.00')
            // Paid already, and posted against the line rather than the order — the row has to
            // resolve that key back to the order or a paid purchase reads as unpaid forever.
            ->assertJsonPath('data.0.investors_share', '168.00')
            ->assertJsonPath('data.0.company_share', '1932.00')
            ->assertJsonPath('data.0.is_posted', true);
    }

    public function test_the_deal_counts_a_purchase_the_press_has_paid_for_though_the_parcel_is_on_the_road(): void
    {
        // Arrange — 300 kg bought off the deal at سعر السادة and paid for the day they left the
        // shelf. The parcel itself is at the press, nowhere near a customer.
        $headers = $this->partner();
        [$deal, , $size, $warehouse] = $this->shipment($headers);
        $order = $this->sale($size, '300', '60.000');
        $this->toTheePress($this->foreman(), $order, $warehouse);

        // Act
        $this->app['auth']->forgetGuards();

        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/investor-deals/{$deal->id}")
            ->assertOk();

        // Assert — the 2,100.00 is in `in_flight`, because that bucket is about **where the
        // parcel is** and this one is still on the road. On this road alone the money under it is
        // not a forecast: the press paid at the warehouse door, and a cancellation now hands the
        // goods to the company rather than back to the deal. Bucketing it as delivered instead
        // would put an order in a row of orders that have reached a customer, which this has not.
        $response
            ->assertJsonPath('data.orders_profit.in_flight.orders', 1)
            ->assertJsonPath('data.orders_profit.in_flight.profit', '2100.00')
            ->assertJsonPath('data.orders_profit.delivered.profit', '0.00')
            ->assertJsonPath('data.orders_profit.total.profit', '2100.00');

        // And it is money the ledger already holds — the investor's share of it was paid when the
        // press bought the bags, so this figure is not waiting on the delivery to become real.
        $this->assertSame('168.00', $response->json('data.balances.profit'));
    }

    public function test_a_deal_still_riding_the_sale_is_shown_as_not_paid_until_delivery(): void
    {
        // Arrange — no سعر السادة, so the partners are on the old road.
        $headers = $this->partner();
        [$deal, , $size, $warehouse] = $this->shipment($headers, printingSalePrice: null);
        $order = $this->sale($size, '300', '60.000');
        $foreman = $this->foreman();
        $this->toTheePress($foreman, $order, $warehouse);

        // Act — read while the job is still at the press.
        $this->app['auth']->forgetGuards();

        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/orders/{$order->id}/investor-shares")
            ->assertOk();

        // Assert — the row exists and names what is coming, and says plainly that nothing has
        // been taken yet. An order in flight that showed no row at all would read as «لا علاقة
        // لهذه الطلبية بأي مستثمر», which is the opposite of true.
        $response
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.deal_code', $deal->code)
            ->assertJsonPath('data.0.kind', 'order_profit')
            ->assertJsonPath('data.0.goods_amount', null)
            ->assertJsonPath('data.0.is_paid', false)
            ->assertJsonPath('data.0.paid_amount', null);
    }

    public function test_an_order_that_touched_no_deal_says_nothing(): void
    {
        // Arrange — a printed order off the company's own shelf.
        $headers = $this->partner();
        $warehouse = Warehouse::factory()->create();
        $size = $this->bagSize(true);

        $this->withHeaders($headers)->postJson('/api/v1/stock-movements/arrivals', [
            'stock_item_id' => $size->stock_item_id,
            'to_warehouse_id' => $warehouse->getKey(),
            'quantity' => '500.000',
            'unit_cost' => '25.000',
        ])->assertCreated();

        $order = $this->sale($size, '300', '60.000');
        $this->toTheePress($this->foreman(), $order, $warehouse);

        // Act & Assert — an empty list, not a row of zeros.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($headers)
            ->getJson("/api/v1/orders/{$order->id}/investor-shares")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
