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
use App\Domain\Investor\Enums\DealStatus;
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\InvestorService;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealItem;
use App\Domain\Investor\Models\InvestorDealShare;
use App\Domain\Investor\Models\InvestorDealSupply;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Investor\Queries\InvestorBalances;
use App\Domain\PurchaseOrder\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrder\Models\PurchaseOrder;
use App\Domain\PurchaseOrder\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A purchase order becomes a funded deal in one act — and the goods arrive carrying it.
 *
 * The screen this covers is the whole point of the change: the materials were being typed into a
 * deal form that duplicated the order, and then linked back to it line by line. Here the order is
 * the deal's paperwork, and what a person types is a name and a column of amounts.
 *
 * Arrange - Act - Assert throughout.
 */
class FundPurchaseOrderTest extends TestCase
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
    private function partner(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInvestors->value,
            PermissionName::ManageInvestors->value,
            PermissionName::RecordInvestorMoney->value,
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
            // Reading the order back — the funding it carries is published on its own screen.
            PermissionName::ViewPurchaseOrders->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** A shelf standing behind one product, under a heading the business invests in. */
    private function investableSize(bool $investable = true): ProductVariant
    {
        $category = ProductCategory::factory()->create(['is_investable' => $investable]);

        $product = Product::factory()->create([
            'pricing_unit' => PricingUnit::Piece,
            'product_category_id' => $category->getKey(),
            'is_active' => true,
        ]);

        return ProductVariant::factory()->for($product)->create([
            'stock_item_id' => StockItem::factory()->unit(PricingUnit::Piece)->create()->getKey(),
        ]);
    }

    /**
     * An order for [$sizes], 10,000 units of each at 10.000 landed — 100,000 a line.
     *
     * Priced well above what the tests put into it on purpose: the money may never exceed what
     * the goods cost, so a shipment worth less than the partners' cash would refuse for a reason
     * the test was not written to measure.
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
                'base_total_cost' => '100000.00',
                'base_unit_cost' => '10.000',
                'allocated_additional_cost' => '0.00',
                'final_unit_cost' => '10.000',
                'final_total_cost' => '100000.00',
            ]);
        }

        return $order->refresh();
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

    // ─────────────────────────── the one act ───────────────────────────

    public function test_funding_an_order_creates_the_deal_its_shelves_its_claims_and_the_money(): void
    {
        // Arrange — one order for one shelf, two partners with money in their wallets.
        $headers = $this->partner();
        $size = $this->investableSize();
        $order = $this->order([$size]);
        $ahmed = $this->investorHolding('50000.00');
        $mohamed = $this->investorHolding('50000.00');

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investor_profit_share_percent' => 50,
                'investors' => [
                    ['investor_id' => $ahmed->getKey(), 'amount' => '30000.00'],
                    ['investor_id' => $mohamed->getKey(), 'amount' => '20000.00'],
                ],
            ],
        );

        // Assert — the deal is open, born from the order, and carries its shelf.
        $response->assertCreated();

        $deal = InvestorDeal::query()->where('purchase_order_id', $order->id)->firstOrFail();

        $this->assertSame(DealStatus::Open, $deal->status);

        $this->assertSame(
            [$size->stock_item_id],
            InvestorDealItem::query()->where('investor_deal_id', $deal->getKey())
                ->pluck('stock_item_id')->map(fn ($id) => (int) $id)->all(),
        );

        // Every line claimed, so the receipt answers itself.
        $this->assertDatabaseHas('investor_deal_supplies', [
            'investor_deal_id' => $deal->getKey(),
            'source_type' => 'purchase_order',
            'source_id' => $order->id,
            'stock_item_id' => $size->stock_item_id,
        ]);

        // The percentages are the money: 30,000 and 20,000 are 60 and 40.
        $shares = InvestorDealShare::query()->where('investor_deal_id', $deal->getKey())
            ->get()->keyBy('investor_id');

        $this->assertSame('60.0000', (string) $shares[$ahmed->getKey()]->share_percent);
        $this->assertSame('40.0000', (string) $shares[$mohamed->getKey()]->share_percent);
        $this->assertSame('30000.00', (string) $shares[$ahmed->getKey()]->committed_amount);

        // And the money actually moved out of the wallets into the deal.
        $balances = app(InvestorBalances::class)->forInvestor((int) $ahmed->getKey());

        $this->assertSame('20000.00', $balances['wallet']['capital']);
        $this->assertSame('30000.00', $balances['deals'][(int) $deal->getKey()]['capital']);
    }

    public function test_the_goods_arrive_carrying_the_deal_without_anyone_choosing_it(): void
    {
        // Arrange — an order funded before the lorry left.
        $headers = $this->partner();
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        $order = $this->order([$size], $warehouse);
        $ahmed = $this->investorHolding('50000.00');

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '30000.00']],
            ],
        )->assertCreated();

        $deal = InvestorDeal::query()->where('purchase_order_id', $order->id)->firstOrFail();

        // Act — the storekeeper receives it, and is asked nothing about deals.
        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/arrivals",
            ['items' => [['stock_item_id' => $size->stock_item_id, 'quantity' => '10000.000']]],
        )->assertCreated();

        // Assert — the cost layer that opened carries the deal.
        $batch = StockBatch::query()->where('stock_item_id', $size->stock_item_id)->latest('id')->firstOrFail();

        $this->assertSame((int) $deal->getKey(), (int) $batch->investor_deal_id);
        $this->assertSame('10.000', (string) $batch->unit_cost);
    }

    /**
     * **Undoing a receipt takes the deal's goods back and leaves its money entirely alone.**
     *
     * The two halves are settled at different moments and by different acts: an investor's cash
     * moves when the deal is *funded*, and his stock is bought off him when it *leaves the shelf*
     * (`PostDealStockPurchases`). A receipt entered in error sits between the two — the goods
     * never left — so there is nothing owed to unwind, and the wallet must not so much as twitch.
     * The layer simply goes back to nothing and the lorry is waiting to be received again.
     */
    public function test_undoing_a_receipt_takes_the_deals_stock_back_without_touching_its_money(): void
    {
        // Arrange — funded, and received.
        $headers = $this->partner();
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        $order = $this->order([$size], $warehouse);
        $ahmed = $this->investorHolding('50000.00');

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            ['investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '30000.00']]],
        )->assertCreated();

        $deal = InvestorDeal::query()->where('purchase_order_id', $order->id)->firstOrFail();

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/arrivals",
            ['items' => [['stock_item_id' => $size->stock_item_id, 'quantity' => '10000.000']]],
        )->assertCreated();

        $walletBefore = InvestorWalletEntry::query()->where('investor_id', $ahmed->getKey())->get();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/receipt-reversal",
            ['reason' => 'سُجّلت الشحنة على الأمر الخطأ'],
        );

        // Assert — the deal's stock is gone from the shelf …
        $response->assertOk()->assertJsonPath('data.status', 'arrived');

        $batch = StockBatch::query()->where('stock_item_id', $size->stock_item_id)->latest('id')->firstOrFail();
        $this->assertSame((int) $deal->getKey(), (int) $batch->investor_deal_id);
        $this->assertSame('0.000', (string) $batch->quantity_remaining);

        // … the deal itself is untouched and still open, waiting for the goods to arrive again …
        $this->assertSame(DealStatus::Open, $deal->refresh()->status);

        // … and not one dinar moved.
        $walletAfter = InvestorWalletEntry::query()->where('investor_id', $ahmed->getKey())->get();
        $this->assertSame($walletBefore->count(), $walletAfter->count());
        $this->assertSame(
            $walletBefore->sum(fn (InvestorWalletEntry $entry): string => (string) $entry->amount),
            $walletAfter->sum(fn (InvestorWalletEntry $entry): string => (string) $entry->amount),
        );
    }

    // ─────────────────────────── what it refuses ───────────────────────────

    public function test_an_order_that_has_started_arriving_cannot_be_funded(): void
    {
        // Arrange — one line already received, so there are layers nothing can be stamped onto.
        $headers = $this->partner();
        $size = $this->investableSize();
        $warehouse = Warehouse::factory()->create();
        $order = $this->order([$size], $warehouse);
        $ahmed = $this->investorHolding('50000.00');

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/arrivals",
            ['items' => [['stock_item_id' => $size->stock_item_id, 'quantity' => '4000.000']]],
        )->assertCreated();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '30000.00']],
            ],
        );

        // Assert
        $response->assertStatus(422);
        $this->assertSame(0, InvestorDeal::query()->count());
        $this->assertSame(0, InvestorWalletEntry::query()->where('type', WalletEntryType::Allocation->value)->count());
    }

    public function test_one_order_can_carry_a_deal_for_each_of_its_lines(): void
    {
        // Arrange — one lorry, two shelves, two different sets of partners.
        $headers = $this->partner();
        $white = $this->investableSize();
        $brown = $this->investableSize();
        $order = $this->order([$white, $brown]);
        $ahmed = $this->investorHolding('50000.00');
        $mohamed = $this->investorHolding('50000.00');

        // Act — each deal names the line it is taking.
        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'stock_item_ids' => [$white->stock_item_id],
                'investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '30000.00']],
            ],
        )->assertCreated();

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'stock_item_ids' => [$brown->stock_item_id],
                'investors' => [['investor_id' => $mohamed->getKey(), 'amount' => '20000.00']],
            ],
        )->assertCreated();

        // Assert — two deals, and each claims its own line only.
        $deals = InvestorDeal::query()->where('purchase_order_id', $order->id)->orderBy('id')->get();

        $this->assertCount(2, $deals);

        $this->assertDatabaseHas('investor_deal_supplies', [
            'investor_deal_id' => $deals[0]->getKey(),
            'stock_item_id' => $white->stock_item_id,
        ]);
        $this->assertDatabaseHas('investor_deal_supplies', [
            'investor_deal_id' => $deals[1]->getKey(),
            'stock_item_id' => $brown->stock_item_id,
        ]);
    }

    public function test_a_line_already_funded_is_refused_a_second_deal(): void
    {
        // Arrange — the first deal took one of two lines.
        $headers = $this->partner();
        $white = $this->investableSize();
        $brown = $this->investableSize();
        $order = $this->order([$white, $brown]);
        $ahmed = $this->investorHolding('50000.00');
        $mohamed = $this->investorHolding('50000.00');

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'stock_item_ids' => [$white->stock_item_id],
                'investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '10000.00']],
            ],
        )->assertCreated();

        // Act — the second reaches for the same line.
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'stock_item_ids' => [$white->stock_item_id],
                'investors' => [['investor_id' => $mohamed->getKey(), 'amount' => '10000.00']],
            ],
        );

        // Assert — refused, because the receipt takes one answer per line.
        $response->assertStatus(422);
        $this->assertSame(1, InvestorDeal::query()->count());
    }

    public function test_omitting_the_lines_takes_whatever_is_left_unfunded(): void
    {
        // Arrange — one of two lines already taken.
        $headers = $this->partner();
        $white = $this->investableSize();
        $brown = $this->investableSize();
        $order = $this->order([$white, $brown]);
        $ahmed = $this->investorHolding('50000.00');
        $mohamed = $this->investorHolding('50000.00');

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'stock_item_ids' => [$white->stock_item_id],
                'investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '10000.00']],
            ],
        )->assertCreated();

        // Act — the second names no lines at all.
        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investors' => [['investor_id' => $mohamed->getKey(), 'amount' => '10000.00']],
            ],
        )->assertCreated();

        // Assert — it took the remainder, not the whole order.
        $second = InvestorDeal::query()->where('purchase_order_id', $order->id)->orderByDesc('id')->firstOrFail();

        $this->assertSame(
            [$brown->stock_item_id],
            InvestorDealItem::query()->where('investor_deal_id', $second->getKey())
                ->pluck('stock_item_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_the_order_screen_names_who_funded_it_and_with_what_percentage(): void
    {
        // Arrange
        $headers = $this->partner();
        $size = $this->investableSize();
        $order = $this->order([$size]);
        $ahmed = $this->investorHolding('50000.00');
        $mohamed = $this->investorHolding('50000.00');

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investors' => [
                    ['investor_id' => $ahmed->getKey(), 'amount' => '30000.00'],
                    ['investor_id' => $mohamed->getKey(), 'amount' => '20000.00'],
                ],
            ],
        )->assertCreated();

        // Act — the purchase order's own screen.
        $response = $this->withHeaders($headers)->getJson("/api/v1/purchase-orders/{$order->id}");

        // Assert — the deal, and each man's money beside the percentage it produced.
        $response->assertOk()
            ->assertJsonPath('data.investor_funding.0.investors.0.name', $ahmed->name)
            ->assertJsonPath('data.investor_funding.0.investors.0.committed_amount', '30000.00')
            ->assertJsonPath('data.investor_funding.0.investors.0.share_percent', '60.0000')
            ->assertJsonPath('data.investor_funding.0.investors.1.share_percent', '40.0000');
    }

    public function test_an_order_whose_only_line_is_funded_is_refused_a_second_deal(): void
    {
        // Arrange
        $headers = $this->partner();
        $size = $this->investableSize();
        $order = $this->order([$size]);
        $ahmed = $this->investorHolding('50000.00');
        $mohamed = $this->investorHolding('50000.00');

        $body = fn (int $investorId) => [
            'investors' => [['investor_id' => $investorId, 'amount' => '10000.00']],
        ];

        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            $body((int) $ahmed->getKey()),
        )->assertCreated();

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            $body((int) $mohamed->getKey()),
        );

        // Assert — one order, one answer to «من موّله؟».
        $response->assertStatus(422);
        $this->assertSame(1, InvestorDeal::query()->count());
    }

    public function test_a_shelf_the_business_does_not_invest_in_refuses_the_whole_lorry(): void
    {
        // Arrange — two lines, one of them outside an investable category.
        $headers = $this->partner();
        $order = $this->order([$this->investableSize(), $this->investableSize(investable: false)]);
        $ahmed = $this->investorHolding('50000.00');

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '30000.00']],
            ],
        );

        // Assert — nothing at all was written, not even the deal that got as far as its shelves.
        $response->assertStatus(422);
        $this->assertSame(0, InvestorDeal::query()->count());
        $this->assertSame(0, InvestorDealSupply::query()->count());
    }

    public function test_a_stake_under_a_thousand_is_refused(): void
    {
        // Arrange — one partner at 30,000 and one at 500.
        $headers = $this->partner();
        $order = $this->order([$this->investableSize()]);
        $ahmed = $this->investorHolding('50000.00');
        $mohamed = $this->investorHolding('50000.00');

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investors' => [
                    ['investor_id' => $ahmed->getKey(), 'amount' => '30000.00'],
                    ['investor_id' => $mohamed->getKey(), 'amount' => '500.00'],
                ],
            ],
        );

        // Assert — a stake that small buys a share that rounds to noise and a partner to answer
        // to at closing. Nothing is written, including the partner who was in order.
        $response->assertStatus(422);
        $this->assertSame(0, InvestorDeal::query()->count());
        $this->assertSame(0, InvestorWalletEntry::query()->where('type', WalletEntryType::Allocation->value)->count());
    }

    public function test_exactly_a_thousand_is_allowed(): void
    {
        // Arrange — the floor itself, which a `>` would have refused.
        $headers = $this->partner();
        $order = $this->order([$this->investableSize()]);
        $ahmed = $this->investorHolding('50000.00');

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            ['investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '1000.00']]],
        );

        // Assert
        $response->assertCreated();
        $this->assertSame(1, InvestorDeal::query()->count());
    }

    public function test_the_money_may_never_exceed_what_the_goods_cost(): void
    {
        // Arrange — one line worth 100,000, and two men reaching for 120,000 of it.
        $headers = $this->partner();
        $size = $this->investableSize();
        $order = $this->order([$size]);
        $ahmed = $this->investorHolding('200000.00');
        $mohamed = $this->investorHolding('200000.00');

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investors' => [
                    ['investor_id' => $ahmed->getKey(), 'amount' => '60000.00'],
                    ['investor_id' => $mohamed->getKey(), 'amount' => '60000.00'],
                ],
            ],
        );

        // Assert — money beyond the goods buys nothing, and would earn on a shipment it did not
        // pay for. Nothing is written.
        $response->assertStatus(422);
        $this->assertSame(0, InvestorDeal::query()->count());
        $this->assertSame(0, InvestorWalletEntry::query()->where('type', WalletEntryType::Allocation->value)->count());
    }

    public function test_lines_nobody_has_priced_are_refused_before_the_lorry_leaves(): void
    {
        // Arrange — a line with no cost typed on it.
        $headers = $this->partner();
        $size = $this->investableSize();
        $order = PurchaseOrder::factory()->create([
            'warehouse_id' => Warehouse::factory()->create()->getKey(),
            'status' => PurchaseOrderStatus::New,
        ]);
        PurchaseOrderItem::factory()->forOrder($order)->create([
            'stock_item_id' => $size->stock_item_id,
            'quantity_ordered' => '10000.000',
            'base_total_cost' => null,
            'base_unit_cost' => null,
            'allocated_additional_cost' => null,
            'final_unit_cost' => null,
            'final_total_cost' => null,
        ]);
        $ahmed = $this->investorHolding('50000.00');

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            ['investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '30000.00']]],
        );

        // Assert — the same rule the receipt already holds, said where the price can still be typed.
        $response->assertStatus(422);
        $this->assertSame(0, InvestorDeal::query()->count());
    }

    public function test_a_man_cannot_put_in_what_his_wallet_has_not_got(): void
    {
        // Arrange — 30,000 promised against 10,000 held.
        $headers = $this->partner();
        $size = $this->investableSize();
        $order = $this->order([$size]);
        $ahmed = $this->investorHolding('10000.00');

        // Act
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investors' => [['investor_id' => $ahmed->getKey(), 'amount' => '30000.00']],
            ],
        );

        // Assert — the whole act rolls back; no deal, no claim, no half-funded shipment.
        $response->assertStatus(422);
        $this->assertSame(0, InvestorDeal::query()->count());
        $this->assertSame(0, InvestorDealSupply::query()->count());
        $this->assertSame('10000.00', app(InvestorBalances::class)->forInvestor((int) $ahmed->getKey())['wallet']['capital']);
    }

    public function test_three_equal_partners_still_sum_to_exactly_one_hundred(): void
    {
        // Arrange — the split that has no exact answer in four places.
        $headers = $this->partner();
        $order = $this->order([$this->investableSize()]);
        $men = [
            $this->investorHolding('20000.00'),
            $this->investorHolding('20000.00'),
            $this->investorHolding('20000.00'),
        ];

        // Act
        $this->withHeaders($headers)->postJson(
            "/api/v1/purchase-orders/{$order->id}/investor-funding",
            [
                'investors' => array_map(
                    fn (Investor $man) => ['investor_id' => $man->getKey(), 'amount' => '10000.00'],
                    $men,
                ),
            ],
        )->assertCreated();

        // Assert — 33.3334 + 33.3333 + 33.3333, not three numbers that miss.
        $deal = InvestorDeal::query()->where('purchase_order_id', $order->id)->firstOrFail();

        $percents = InvestorDealShare::query()->where('investor_deal_id', $deal->getKey())
            ->pluck('share_percent')->map(fn ($p) => (string) $p)->all();

        $sum = '0';

        foreach ($percents as $percent) {
            $sum = bcadd($sum, $percent, 4);
        }

        $this->assertSame('100.0000', $sum);
    }
}
