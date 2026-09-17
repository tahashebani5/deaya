<?php

declare(strict_types=1);

namespace Tests\Feature\Investors;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Catalog\Models\ProductVariant;
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
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\TransitionFields;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use App\Domain\Reporting\Queries\ProfitAndLossFilters;
use App\Domain\Reporting\Queries\ProfitAndLossSummaryQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What happens to سعر السادة money when the figure it was computed from is *corrected*.
 *
 * {@see PlainStockPurchaseTest} covers the road when nothing changes. This file covers the two
 * moves that change it after the investor has already been paid — the press restating what the
 * run actually used, and the customer refusing the parcel — plus the two read models that report
 * the result: the deal's own stock position, and the company-wide profit statement.
 *
 * The rule underneath all of it: **a payment is reversed by what was posted, never by what is
 * now computed.** A correction that no longer reaches a deal must still find that deal's earlier
 * money and take it back, or the investor holds the cash and the goods at once.
 *
 * Arrange - Act - Assert throughout.
 */
class StockPurchaseCorrectionTest extends TestCase
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
     * A printed size, priced in `$sold` and stocked by the kilo.
     *
     * Passing `PricingUnit::Piece` is what puts the line on the restatement road: the press
     * weighs kilos while the customer buys bags, so «جاهزة» asks what the run actually used.
     */
    private function bagSize(PricingUnit $sold = PricingUnit::Kilogram): ProductVariant
    {
        $product = Product::factory()->create([
            'pricing_unit' => $sold,
            'product_category_id' => ProductCategory::factory()->investable()->create()->getKey(),
            'is_active' => true,
        ]);

        return ProductVariant::factory()->for($product)->create([
            'label' => '25*35',
            'stock_item_id' => StockItem::factory()->unit(PricingUnit::Kilogram)->create()->getKey(),
        ]);
    }

    /** One line, received whole — funded first when `$printingSalePrice` is given. */
    private function arrival(
        array $headers,
        ProductVariant $size,
        Warehouse $warehouse,
        string $kilos,
        string $unitCost,
        ?string $printingSalePrice = null,
    ): ?InvestorDeal {
        $order = PurchaseOrder::factory()->create([
            'warehouse_id' => $warehouse->getKey(),
            'status' => PurchaseOrderStatus::New,
        ]);

        PurchaseOrderItem::factory()->forOrder($order)->create([
            'stock_item_id' => $size->stock_item_id,
            'quantity_ordered' => $kilos,
            'base_total_cost' => bcmul($kilos, $unitCost, 2),
            'base_unit_cost' => $unitCost,
            'allocated_additional_cost' => '0.00',
            'final_unit_cost' => $unitCost,
            'final_total_cost' => bcmul($kilos, $unitCost, 2),
        ]);

        if ($printingSalePrice !== null) {
            $this->withHeaders($headers)->postJson(
                "/api/v1/purchase-orders/{$order->id}/investor-funding",
                [
                    'investor_profit_share_percent' => 50,
                    'printing_sale_price' => $printingSalePrice,
                    'investors' => [
                        ['investor_id' => $this->investorHolding('5000.00')->getKey(), 'amount' => '1000.00'],
                        ['investor_id' => $this->investorHolding('5000.00')->getKey(), 'amount' => '1000.00'],
                    ],
                ],
            )->assertCreated();
        }

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/arrivals",
            ['items' => [['stock_item_id' => $size->stock_item_id, 'quantity' => $kilos]]],
        )->assertCreated();

        return $printingSalePrice === null
            ? null
            : InvestorDeal::query()->where('purchase_order_id', $order->id)->firstOrFail();
    }

    private function sale(ProductVariant $size, string $quantity, string $unitPrice): Order
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
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => bcmul($quantity, $unitPrice, 2),
            'pricing_unit' => $size->product->pricing_unit,
        ]);

        app(RecalculateOrderTotals::class)($order->refresh());
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

    private function paid(InvestorDeal $deal, WalletEntryType $type): string
    {
        return (string) InvestorWalletEntry::query()
            ->where('investor_deal_id', $deal->getKey())
            ->where('type', $type->value)
            ->whereDoesntHave('reversedBy')
            ->sum('amount');
    }

    // ─────────────────────────── the correction ───────────────────────────

    public function test_a_restatement_that_no_longer_buys_anything_takes_the_investors_money_back(): void
    {
        // Arrange — 400 kg of the company's own stock on the shelf first, then 200 kg financed
        // at 25.000 and sold to the press at 32.000. FIFO reaches the deal's layer only on a
        // draw bigger than 400.
        $headers = $this->partner();
        $warehouse = Warehouse::factory()->create();
        $size = $this->bagSize(PricingUnit::Piece);

        $this->arrival($headers, $size, $warehouse, '400.000', '20.000');
        $deal = $this->arrival($headers, $size, $warehouse, '200.000', '25.000', '32.000');

        $order = $this->sale($size, '1000', '60.000');
        $line = $order->items()->firstOrFail();
        $foreman = $this->foreman();

        // Act — the warehouse weighs 500 kg out: 400 the company's, 100 the deal's.
        $this->move($foreman, $order, OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
            TransitionFields::stockQuantityKey($line) => '500.000',
        ])->assertOk();

        // 100 × (32 − 25) = 700, of which the partners own 2,000 ÷ 5,000 = 40% and keep half.
        $this->assertSame('140.00', $this->paid($deal, WalletEntryType::Profit));

        // Act — the press reports it actually used 300 kg, which no longer reaches his layer.
        $this->move($foreman, $order->refresh(), OrderStatus::Printing)->assertOk();
        $this->move($foreman, $order->refresh(), OrderStatus::Ready, [
            TransitionFields::stockQuantityKey($line) => '300.000',
        ])->assertOk();

        // Assert — the line bought nothing after the correction, and neither does his ledger
        // still say it did. His 200 kg are whole on the shelf; he may not also hold the money.
        $line->refresh();
        $this->assertNull($line->stock_purchased_at);
        $this->assertSame('0', $this->paid($deal, WalletEntryType::Profit));

        // And the reversal is written, not the original erased — every money table here is a
        // ledger with corrections in it.
        $this->assertSame('140.00', (string) InvestorWalletEntry::query()
            ->where('investor_deal_id', $deal->getKey())
            ->where('type', WalletEntryType::Reversal->value)
            ->sum('amount'));
    }

    public function test_a_restatement_that_drops_one_of_two_deals_still_reverses_that_deal(): void
    {
        // Arrange — two financed lorries on one shelf: 100 kg at 25.000 sold at 32.000 (older,
        // drawn first), then 300 kg at 24.000 sold at 30.000.
        $headers = $this->partner();
        $warehouse = Warehouse::factory()->create();
        $size = $this->bagSize(PricingUnit::Piece);

        $first = $this->arrival($headers, $size, $warehouse, '100.000', '25.000', '32.000');
        $second = $this->arrival($headers, $size, $warehouse, '300.000', '24.000', '30.000');

        $order = $this->sale($size, '1000', '60.000');
        $line = $order->items()->firstOrFail();
        $foreman = $this->foreman();

        // Act — 200 kg out: all 100 of the first, 100 of the second.
        $this->move($foreman, $order, OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
            TransitionFields::stockQuantityKey($line) => '200.000',
        ])->assertOk();

        $this->assertNotSame('0', $this->paid($second, WalletEntryType::Profit));

        // Act — the run used 80 kg, which FIFO satisfies out of the first deal alone.
        $this->move($foreman, $order->refresh(), OrderStatus::Printing)->assertOk();
        $this->move($foreman, $order->refresh(), OrderStatus::Ready, [
            TransitionFields::stockQuantityKey($line) => '80.000',
        ])->assertOk();

        // Assert — the first deal is corrected down to 80 × (32 − 25) × 80% × 50% = 224.00 (its
        // partners put 2,000 into a 2,500 lorry), and the second, whose goods went back onto its
        // own layer whole, keeps nothing at all.
        $this->assertSame('224.00', $this->paid($first, WalletEntryType::Profit));
        $this->assertSame('0', $this->paid($second, WalletEntryType::Profit));
    }

    // ─────────────────────────── the deal's own statement ───────────────────────────

    public function test_goods_a_cancellation_handed_to_the_company_are_reported_as_sold(): void
    {
        // Arrange — 500 kg financed, 300 of them bought by the press and paid for.
        $headers = $this->partner();
        $warehouse = Warehouse::factory()->create();
        $size = $this->bagSize();

        $deal = $this->arrival($headers, $size, $warehouse, '500.000', '25.000', '32.000');
        $order = $this->sale($size, '300', '60.000');
        $foreman = $this->foreman();

        $this->move($foreman, $order, OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
        ])->assertOk();

        // Act — «استلم الزبون ما استلمش، المطبعة تتحمّل»: the goods become the company's.
        $this->withHeaders($foreman)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Cancelled->value,
            'reason' => 'رفض الزبون استلام الطلبية',
        ])->assertOk();

        // Assert — 300 kg left his layers and never came back, so his statement says so. The
        // shipment still adds up: 200 on the shelf plus 300 gone is the 500 that arrived.
        $position = app(InvestorService::class)->dealStock((int) $deal->getKey());

        $this->assertSame('200.000', $position['quantity_remaining']);
        $this->assertSame('300.000', $position['quantity_sold']);
        $this->assertSame('500.000', $position['quantity_received']);
    }

    // ─────────────────────────── closing the deal ───────────────────────────

    public function test_a_deal_stays_open_while_the_press_can_still_restate_what_it_used(): void
    {
        // Arrange — 500 kg financed and every one of them handed to the press, so the shelf is
        // bare and `stillHoldsStock()` says nothing is left. The order stands at «جاهزة للطباعة»,
        // one move short of the «جاهزة» that lets the press correct the weight.
        $headers = $this->partner();
        $warehouse = Warehouse::factory()->create();
        $size = $this->bagSize(PricingUnit::Piece);

        $deal = $this->arrival($headers, $size, $warehouse, '500.000', '25.000', '32.000');
        $order = $this->sale($size, '1000', '60.000');
        $line = $order->items()->firstOrFail();
        $foreman = $this->foreman();

        $this->move($foreman, $order, OrderStatus::ReadyToPrint, [
            'warehouse_id' => $warehouse->getKey(),
            TransitionFields::stockQuantityKey($line) => '500.000',
        ])->assertOk();

        // Act — the owner tries to close it and pay everybody out. The guard still holds the
        // foreman it resolved a moment ago, so it is forgotten before the owner speaks.
        $this->app['auth']->forgetGuards();
        $refusal = $this->withHeaders($headers)->postJson("/api/v1/investor-deals/{$deal->id}/close");

        // Assert — refused. A restatement at «جاهزة» credits the goods back to *this deal's*
        // own layers, so closing here would release capital and profit for withdrawal against
        // stock about to reappear on the shelf.
        $refusal->assertStatus(422);
        $this->assertSame('open', (string) $deal->refresh()->status->value);

        // Act — the press reports the run and the order reaches «جاهزة». The guard is holding
        // the owner now, so it is forgotten again before the foreman speaks.
        $this->app['auth']->forgetGuards();
        $this->move($foreman, $order->refresh(), OrderStatus::Printing)->assertOk();
        $this->move($foreman, $order->refresh(), OrderStatus::Ready, [
            TransitionFields::stockQuantityKey($line) => '480.000',
        ])->assertOk();

        // Assert — the correction is behind it now and nothing can put stock back, so the deal
        // closes. The 20 kg the run did not use are on the shelf again and hold it open.
        $this->assertSame('20.000', (string) StockBatch::query()
            ->where('investor_deal_id', $deal->getKey())
            ->sum('quantity_remaining'));
    }

    // ─────────────────────────── the company's own statement ───────────────────────────

    public function test_the_profit_statement_costs_goods_at_what_they_cost_not_at_سعر_السادة(): void
    {
        // Arrange — one delivered order that paid 9,600.00 for material the business bought for
        // 7,500.00. The 2,100.00 between them moved from one internal pocket to another.
        $order = Order::factory()->create([
            'status' => OrderStatus::Delivered,
            'delivered_at' => Carbon::parse('2026-09-06 10:00:00'),
            'items_total' => '18000.00',
            'design_fee' => '0.00',
            'delivery_price' => '0.00',
            'discount' => '0.00',
            'additional_cost' => '0.00',
            'total_cogs' => '10800.00',
        ]);

        OrderItem::factory()->for($order)->create([
            'material_cost' => '9600.00',
            'material_cost_actual' => '7500.00',
            'labor_cost' => '1200.00',
            'overhead_cost' => '0.00',
        ]);

        // Act
        $summary = app(ProfitAndLossSummaryQuery::class)(new ProfitAndLossFilters(
            from: Carbon::parse('2026-09-01'),
            to: Carbon::parse('2026-09-30'),
        ));

        // Assert — 18,000 − 7,500 − 1,200 = 9,300. Costing the material at what the press was
        // charged would report 7,200 and lose the company its own share of every margin.
        $this->assertSame('7500.00', $summary['cost_of_goods_sold']['material']);
        $this->assertSame('8700.00', $summary['cost_of_goods_sold']['total']);
        $this->assertSame('9300.00', $summary['gross_profit']);
    }

    // ─────────────────────────── the price itself ───────────────────────────

    public function test_a_price_that_would_round_away_to_nothing_is_refused_at_the_door(): void
    {
        // Arrange — a lorry to fund, and a سعر السادة below the third decimal the column keeps.
        $headers = $this->partner();
        $warehouse = Warehouse::factory()->create();
        $size = $this->bagSize();

        $order = PurchaseOrder::factory()->create([
            'warehouse_id' => $warehouse->getKey(),
            'status' => PurchaseOrderStatus::New,
        ]);

        PurchaseOrderItem::factory()->forOrder($order)->create([
            'stock_item_id' => $size->stock_item_id,
            'quantity_ordered' => '100.000',
            'base_total_cost' => '2500.00',
            'base_unit_cost' => '25.000',
            'allocated_additional_cost' => '0.00',
            'final_unit_cost' => '25.000',
            'final_total_cost' => '2500.00',
        ]);

        // Act — `gt:0` lets it through; the column it is rounded into cannot hold it.
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'printing_sale_price' => '0.0004',
                'investors' => [
                    ['investor_id' => $this->investorHolding('5000.00')->getKey(), 'amount' => '1000.00'],
                ],
            ],
        );

        // Assert — a refusal naming the field, not a 500 from the database.
        $response->assertStatus(422)->assertJsonValidationErrors('printing_sale_price');
    }
}
