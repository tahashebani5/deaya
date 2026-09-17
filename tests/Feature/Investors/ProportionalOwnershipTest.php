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
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Investor\DTOs\WalletEntryData;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\InvestorService;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorDeal;
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
 * The company is a partner for whatever the investors did not cover.
 *
 * The owner's rule, in his words: «50% لرأس المال — الشركة لما تحط فلوس تكون كأنها طرف تاني.
 * الشريك 1: 17 الشركة، الشريك 2: 3 عمر. الـ50% تنقسم بيناتنا.» Three men who put 3,000 into a
 * 20,000 shipment own 15% of it. The investors' half is the half of what *that* 15% earns; the
 * other 85% is the company's whole. Every figure below is the owner's own example, at his
 * numbers, so he can check it on paper.
 *
 * Arrange - Act - Assert throughout.
 */
class ProportionalOwnershipTest extends TestCase
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

    /**
     * @return array<string, string>
     */
    private function partner(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInvestors->value,
            PermissionName::ManageInvestors->value,
            PermissionName::RecordInvestorMoney->value,
            PermissionName::RecordDealExpenses->value,
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
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
        ]);

        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** A man with money in his wallet and none of it committed anywhere. */
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
     * The owner's shipment: 10,000 units of each shelf at 2.000 landed — 20,000 a line.
     *
     * @param  list<ProductVariant>  $sizes
     */
    private function order(array $sizes, ?Warehouse $warehouse = null): PurchaseOrder
    {
        $order = PurchaseOrder::factory()->create([
            'warehouse_id' => ($warehouse ?? Warehouse::factory()->create())->getKey(),
            'status' => PurchaseOrderStatus::New,
        ]);

        foreach ($sizes as $size) {
            PurchaseOrderItem::factory()->forOrder($order)->create([
                'stock_item_id' => $size->stock_item_id,
                'quantity_ordered' => '10000.000',
                'base_total_cost' => '20000.00',
                'base_unit_cost' => '2.000',
                'allocated_additional_cost' => '0.00',
                'final_unit_cost' => '2.000',
                'final_total_cost' => '20000.00',
            ]);
        }

        return $order->refresh();
    }

    /**
     * The owner's deal: three partners with 1,000 each into a 20,000 order, the company carrying
     * the other 17,000 — funded through the real endpoint, so the percent is the one it derives.
     *
     * @return array{0: InvestorDeal, 1: list<Investor>, 2: PurchaseOrder}
     */
    private function ownersDeal(array $headers, ?Warehouse $warehouse = null): array
    {
        $size = $this->investableSize();
        $order = $this->order([$size], $warehouse);
        $partners = [$this->investorHolding('5000.00'), $this->investorHolding('5000.00'), $this->investorHolding('5000.00')];

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investor_profit_share_percent' => 50,
                'investors' => array_map(
                    fn (Investor $partner) => ['investor_id' => $partner->getKey(), 'amount' => '1000.00'],
                    $partners,
                ),
            ],
        )->assertCreated();

        return [InvestorDeal::query()->where('purchase_order_id', $order->id)->firstOrFail(), $partners, $order];
    }

    /**
     * The lorry arrives, and the whole shipment is sold to one customer at [$unitPrice].
     *
     * Goods come in through the real arrival path so the cost layer carries the deal exactly as
     * the storekeeper's receipt would stamp it.
     */
    private function receiveAndSell(array $headers, PurchaseOrder $order, Warehouse $warehouse, string $unitPrice): Order
    {
        $line = $order->items()->firstOrFail();
        $size = ProductVariant::query()->where('stock_item_id', $line->stock_item_id)->firstOrFail();

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/arrivals",
            ['items' => [['stock_item_id' => $line->stock_item_id, 'quantity' => '10000.000']]],
        )->assertCreated();

        $sale = Order::factory()->create(['design_fee' => '0.00', 'delivery_price' => '0.00', 'discount' => '0.00']);
        OrderItem::factory()->for($sale)->create([
            'product_id' => $size->product_id,
            'product_variant_id' => $size->getKey(),
            'variant_label' => $size->label,
            'quantity' => '10000',
            'unit_price' => $unitPrice,
            'line_total' => bcmul('10000', $unitPrice, 2),
            'pricing_unit' => PricingUnit::Piece,
        ]);
        app(RecalculateOrderTotals::class)($sale->refresh());

        $this->deliver($this->foreman(), $sale->refresh(), $warehouse);

        return $sale->refresh();
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

    /**
     * What each partner was paid, or charged, on this deal for one type of entry — keyed by
     * investor, as the owner would read it down a column.
     *
     * @param  list<Investor>  $partners
     * @return array<int, string>
     */
    private function entriesOf(InvestorDeal $deal, array $partners, WalletEntryType $type): array
    {
        $amounts = [];

        foreach ($partners as $partner) {
            $amounts[(int) $partner->getKey()] = (string) InvestorWalletEntry::query()
                ->where('investor_deal_id', $deal->getKey())
                ->where('investor_id', $partner->getKey())
                ->where('type', $type->value)
                ->sum('amount');
        }

        return $amounts;
    }

    // ─────────────────────────── what funding records ───────────────────────────

    public function test_funding_part_of_the_cost_records_what_the_company_carries_and_the_fraction_the_partners_own(): void
    {
        // Arrange — 20,000 of goods, 3,000 of partners' money.
        $headers = $this->partner();

        // Act
        [$deal, $partners] = $this->ownersDeal($headers);

        // Assert — the company is a partner for the 17,000 nobody else put in, and the three
        // together own 15% of the shipment. Both derived from the amounts, neither typed.
        $this->assertSame('17000.00', (string) $deal->company_stake);
        $this->assertSame('15.0000', (string) $deal->investor_funded_percent);

        // Among themselves, equal thirds of the investors' part — the extra ten-thousandth
        // landing on the first by largest remainder, as it always has.
        $shares = InvestorDealShare::query()->where('investor_deal_id', $deal->getKey())->orderBy('id')->get();
        $this->assertSame(['33.3334', '33.3333', '33.3333'], $shares->map(fn ($share) => (string) $share->share_percent)->all());

        // And the screen is told both, so it never has to work them out from the order.
        $this->withHeaders($headers)->getJson("/api/v1/investor-deals/{$deal->id}")
            ->assertOk()
            ->assertJsonPath('data.company_stake', '17000.00')
            ->assertJsonPath('data.investor_funded_percent', '15.0000');
    }

    public function test_funding_the_whole_cost_leaves_nothing_on_the_company(): void
    {
        // Arrange — 20,000 of goods, 20,000 of partners' money.
        $headers = $this->partner();
        $size = $this->investableSize();
        $order = $this->order([$size]);
        $ahmed = $this->investorHolding('50000.00');
        $mohamed = $this->investorHolding('50000.00');

        // Act
        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investors' => [
                    ['investor_id' => $ahmed->getKey(), 'amount' => '12000.00'],
                    ['investor_id' => $mohamed->getKey(), 'amount' => '8000.00'],
                ],
            ],
        )->assertCreated();

        // Assert — exactly what every deal was before this rule existed.
        $deal = InvestorDeal::query()->where('purchase_order_id', $order->id)->firstOrFail();

        $this->assertSame('0.00', (string) $deal->company_stake);
        $this->assertSame('100.0000', (string) $deal->investor_funded_percent);
    }

    public function test_the_company_remainder_is_measured_against_the_deal_own_lines_only(): void
    {
        // Arrange — a lorry with two shelves, 20,000 each. The deal takes one of them.
        $headers = $this->partner();
        $first = $this->investableSize();
        $second = $this->investableSize();
        $order = $this->order([$first, $second]);
        $ahmed = $this->investorHolding('50000.00');

        // Act — 3,000 against the first shelf alone.
        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'stock_item_ids' => [$first->stock_item_id],
                'investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '3000.00']],
            ],
        )->assertCreated();

        // Assert — the remainder is the 17,000 of *its* shelf, not the 37,000 of the lorry. A
        // second deal on the second shelf must not find the first deal's goods on the company's
        // side of its own arithmetic.
        $deal = InvestorDeal::query()->where('purchase_order_id', $order->id)->firstOrFail();

        $this->assertSame('17000.00', (string) $deal->company_stake);
        $this->assertSame('15.0000', (string) $deal->investor_funded_percent);
    }

    // ─────────────────────────── what reaches a wallet ───────────────────────────

    public function test_the_partners_are_paid_half_of_what_their_fifteen_percent_earned(): void
    {
        // Arrange — the owner's example: 20,000 of goods sold for 30,000, a profit of 10,000.
        $headers = $this->partner();
        $warehouse = Warehouse::factory()->create();
        [$deal, $partners, $order] = $this->ownersDeal($headers, $warehouse);

        // Act
        $sale = $this->receiveAndSell($headers, $order, $warehouse, '3.000');

        // Assert — the 50% pot is 5,000. Split by capital, the company's 17,000 takes 4,250 of
        // it and each partner's 1,000 takes 250. Which is the same as saying the partners own
        // 15% of the goods: 10,000 × 15% = 1,500, half of which is 750, a third each.
        //
        // Before this rule the same three men were paid 1,666.67 apiece — 5,000 on 3,000 put in.
        $this->assertSame('10000.00', (string) $sale->grossProfit());
        $this->assertSame(
            ['250.00', '250.00', '250.00'],
            array_values($this->entriesOf($deal, $partners, WalletEntryType::Profit)),
        );

        // The order's own screen agrees: the deal earned the whole 10,000, of which 750 is the
        // partners' and the rest, as the residual, the company's. The guard still holds the
        // foreman who walked the order, so it is told to resolve the partner's token afresh.
        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson("/api/v1/investor-deals/{$deal->id}/orders")
            ->assertOk()
            ->assertJsonPath('data.0.profit', '10000.00')
            ->assertJsonPath('data.0.investors_share', '750.00')
            ->assertJsonPath('data.0.company_share', '9250.00');
    }

    public function test_a_loss_is_borne_by_the_same_fraction(): void
    {
        // Arrange — the same goods sold for 18,000, a loss of 2,000.
        $headers = $this->partner();
        $warehouse = Warehouse::factory()->create();
        [$deal, $partners, $order] = $this->ownersDeal($headers, $warehouse);

        // Act
        $this->receiveAndSell($headers, $order, $warehouse, '1.800');

        // Assert — «لو خسرنا نتقاسمها زي مانتقاسم الربح»: 2,000 × 15% = 300, half of it 150,
        // fifty apiece — and not the 333.33 each that half of the whole loss would have been.
        $this->assertSame(
            ['50.00', '50.00', '50.00'],
            array_values($this->entriesOf($deal, $partners, WalletEntryType::Loss)),
        );
        $this->assertSame(0, InvestorWalletEntry::query()->where('type', WalletEntryType::Profit->value)->count());
    }

    public function test_an_expense_on_the_deal_is_charged_by_the_same_fraction(): void
    {
        // Arrange — the owner's deal, and a customs invoice of 1,000 turning up late.
        $headers = $this->partner();
        [$deal, $partners] = $this->ownersDeal($headers);

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/investor-deals/{$deal->id}/expenses", [
            'kind' => 'customs',
            'name' => 'جمرك متأخر',
            'amount' => '1000.00',
            'incurred_on' => now()->toDateString(),
        ])->assertOk();

        // Assert — 1,000 × 15% × 50% = 75, twenty-five apiece. Charged at the old 500 it would
        // have taken from three men, who together own 750 of the profit, two thirds of it for one
        // invoice on goods that are 85% the company's.
        $this->assertSame(
            ['25.00', '25.00', '25.00'],
            array_values($this->entriesOf($deal, $partners, WalletEntryType::Loss)),
        );
    }

    // ─────────────────────────── what the frozen fraction refuses ───────────────────────────

    public function test_a_deal_born_from_an_order_takes_no_more_capital_after_funding(): void
    {
        // Arrange — funded, percent frozen at 15.
        $headers = $this->partner();
        [$deal, $partners] = $this->ownersDeal($headers);
        $omar = $partners[0];

        // Act — he tries to put another 2,000 into the same deal from the investor screen.
        $response = $this->withHeaders($headers)->postJson("/api/v1/investors/{$omar->id}/wallet", [
            'type' => 'allocation',
            'amount' => '2000.00',
            'investor_deal_id' => $deal->getKey(),
        ]);

        // Assert — refused: the money would buy no goods and move no percent, and his capital in
        // the deal would stand at 3,000 beside an ownership worked out from 1,000.
        $response->assertStatus(422);

        $balances = app(InvestorBalances::class)->forInvestor((int) $omar->getKey());
        $this->assertSame('1000.00', $balances['deals'][(int) $deal->getKey()]['capital']);
        $this->assertSame('4000.00', $balances['wallet']['capital']);
    }

    public function test_a_funded_order_can_no_longer_be_edited(): void
    {
        // Arrange — funded at a landed cost of 20,000, and somebody wants to retype it.
        $headers = $this->partner();
        [, , $order] = $this->ownersDeal($headers);
        $line = $order->items()->firstOrFail();

        // Act
        $response = $this->withHeaders($headers)->putJson("/api/v1/purchase-orders/{$order->id}", [
            'vendor_id' => $order->vendor_id,
            'warehouse_id' => $order->warehouse_id,
            'order_date' => $order->order_date->toDateString(),
            'notes' => 'صار أرخص',
            'items' => [
                [
                    'id' => $line->getKey(),
                    'stock_item_id' => $line->stock_item_id,
                    'quantity_ordered' => '10000.000',
                    'base_total_cost' => '15000.00',
                ],
            ],
        ]);

        // Assert — refused, and the cost the percent was worked out from is still on file.
        $response->assertStatus(422);
        $this->assertSame('20000.00', (string) $line->refresh()->final_total_cost);
        $this->assertNotSame('صار أرخص', $order->refresh()->notes);
    }

    public function test_a_deal_built_by_hand_still_takes_capital_and_owns_all_of_its_goods(): void
    {
        // Arrange — the older path: a deal with no purchase order behind it.
        $headers = $this->partner();
        $deal = InvestorDeal::factory()->open()->create();
        $ahmed = $this->investorHolding('50000.00');
        InvestorDealShare::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'investor_id' => $ahmed->getKey(),
            'share_percent' => '100.0000',
        ]);

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/investors/{$ahmed->id}/wallet", [
            'type' => 'allocation',
            'amount' => '30000.00',
            'investor_deal_id' => $deal->getKey(),
        ]);

        // Assert — nothing about it changed: its stake is nobody's but the partners', and money
        // still goes in, because there is no frozen fraction for it to contradict.
        $response->assertCreated();
        $this->assertSame('0.00', (string) $deal->refresh()->company_stake);
        $this->assertSame('100.0000', (string) $deal->investor_funded_percent);
        $this->assertSame('1000.00', $deal->investorsCutOf('2000.00'));
    }
}
