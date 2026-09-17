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
use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealItem;
use App\Domain\Investor\Models\InvestorDealShare;
use App\Domain\Investor\Models\InvestorWalletEntry;
use App\Domain\Order\Actions\ChangeOrderStatus;
use App\Domain\Order\Actions\DeleteOrder;
use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Actions\RecordCarrierSettlement;
use App\Domain\Order\Actions\RecordOrderPayment;
use App\Domain\Order\Actions\RestoreOrder;
use App\Domain\Order\Actions\ReverseOrderPayment;
use App\Domain\Order\Actions\WriteOffOrderBalance;
use App\Domain\Order\DTOs\OrderPaymentData;
use App\Domain\Order\Enums\OrderPaymentType;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Models\OrderPayment;
use App\Domain\Order\Support\StockEffectPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The money half of «حذف» — §٢٫١ and §٧٫١ of Docs/orders/ORDER-DELETE-AND-ARCHIVE.md.
 *
 * The design asked one open question — «الحذف على طلبيةٍ عليها مالٌ مدفوع: رفضٌ أم عكسٌ تلقائي؟»
 * — and it was answered on 2026-09-10: **عكسٌ تلقائي داخل نفس المعاملة**. This file is that
 * answer written down as behaviour:
 *
 * > deleting an order writes a reversal against every live credit entry it carries — payment,
 * > write-off and carrier settlement — inside the transaction that returns the stock and
 * > archives the row; the restore does **not** write them back; and the confirmation says both
 * > things before the button is pressed.
 *
 * The earnings an order posted into the investors' wallets at «تم الاستلام» are unwound the same
 * way and for the same reason: an order that has left the profit-and-loss report may not leave a
 * man paid for it.
 *
 * Driven through the domain actions rather than the API where the rule is the domain's own, and
 * through the real status path where the figure under test is one the status path computes.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderDeletionMoneyTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::factory()->create();
    }

    /**
     * A delivered order of 200.00 with nothing recorded against it yet — the state every money
     * case below starts from.
     *
     * Force-filled to «تم الاستلام» rather than walked there, because what is under test is the
     * ledger rather than the road to it, and the three recording actions all refuse an order
     * that has not reached a status where money is taken.
     */
    private function deliveredOrder(string $lineTotal = '200.00'): Order
    {
        $order = Order::factory()->status(OrderStatus::Delivered)->create([
            'design_fee' => '0.00',
            'delivery_price' => '0.00',
            'discount' => '0.00',
        ]);

        OrderItem::factory()->for($order)->create([
            'quantity' => '100.000',
            'unit_price' => bcdiv($lineTotal, '100', 3),
            'line_total' => $lineTotal,
        ]);

        app(RecalculateOrderTotals::class)($order->refresh());

        return $order->refresh();
    }

    /** @return array<int, string> the live reversal rows on this order, keyed by the entry they undo */
    private function reversalsOn(Order $order): array
    {
        return OrderPayment::query()
            ->where('order_id', $order->getKey())
            ->where('type', OrderPaymentType::Reversal->value)
            ->get()
            ->mapWithKeys(fn (OrderPayment $row) => [(int) $row->reverses_payment_id => (string) $row->amount])
            ->all();
    }

    // ------------------------------------------------ the reversal in §٢٫١

    public function test_deleting_reverses_the_cash_the_write_off_and_the_couriers_takings_at_once(): void
    {
        // Arrange — one order carrying all three kinds of credit entry, which is the case the
        // superseded guard refused outright.
        $order = $this->deliveredOrder();
        $actor = $this->actor();

        $payment = app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '120', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );
        $settlement = app(RecordCarrierSettlement::class)($order->refresh(), '30', 'تحصيل نورس', $actor);
        $writeOff = app(WriteOffOrderBalance::class)($order->refresh(), '50', 'خصم مجاملة', $actor);

        $this->assertSame('120.00', (string) $order->refresh()->paid_amount);

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — three reversals, each pointing at its own original, and the three derived
        // columns back at zero. The originals are still there: a ledger is added to, never
        // rewritten.
        $reversals = $this->reversalsOn($deleted);

        $this->assertCount(3, $reversals);
        $this->assertSame('120.00', $reversals[(int) $payment->getKey()]);
        $this->assertSame('30.00', $reversals[(int) $settlement->getKey()]);
        $this->assertSame('50.00', $reversals[(int) $writeOff->getKey()]);

        $this->assertSame('0.00', (string) $deleted->paid_amount);
        $this->assertSame('0.00', (string) $deleted->written_off_amount);
        $this->assertSame('0.00', (string) $deleted->carrier_settled_amount);

        $this->assertTrue($deleted->trashed());
        $this->assertSame(6, OrderPayment::query()->where('order_id', $deleted->getKey())->count());
    }

    public function test_the_reversal_names_the_delete_so_the_ledger_reads_a_month_later(): void
    {
        // Arrange
        $order = $this->deliveredOrder();
        $actor = $this->actor();

        app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '120', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — the reason is the whole of what a clerk reading this row will have; it names
        // the order and the fact that a delete, not a person at the counter, wrote it.
        $reversal = OrderPayment::query()
            ->where('order_id', $deleted->getKey())
            ->where('type', OrderPaymentType::Reversal->value)
            ->sole();

        $this->assertStringContainsString('حذف الطلبية', (string) $reversal->notes);
        $this->assertStringContainsString((string) $deleted->code, (string) $reversal->notes);
        $this->assertSame((int) $actor->getKey(), (int) $reversal->recorded_by);
    }

    public function test_an_entry_already_reversed_by_hand_is_not_reversed_a_second_time(): void
    {
        // Arrange — somebody caught the mistake before the delete did
        $order = $this->deliveredOrder();
        $actor = $this->actor();

        $payment = app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '120', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );
        app(ReverseOrderPayment::class)($order->refresh(), $payment, 'أُدخلت على الطلبية الخطأ', $actor);

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — one reversal, the one the person wrote. A second would break
        // `order_payments_reverses_payment_id_unique` and read as money taken back twice.
        $this->assertCount(1, $this->reversalsOn($deleted));
        $this->assertSame('0.00', (string) $deleted->paid_amount);
    }

    public function test_an_order_with_no_money_on_it_writes_no_ledger_row_at_all(): void
    {
        // Arrange
        $order = $this->deliveredOrder();
        $actor = $this->actor();

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — nothing to reverse is nothing written, not a zero entry
        $this->assertSame(0, OrderPayment::query()->where('order_id', $deleted->getKey())->count());
        $this->assertTrue($deleted->trashed());
    }

    public function test_restoring_does_not_bring_the_reversed_money_back(): void
    {
        // Arrange — §٢٫١: reversing a reversal is a third financial event nobody asked for.
        $order = $this->deliveredOrder();
        $actor = $this->actor();

        app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '120', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Act
        $restored = app(RestoreOrder::class)($deleted, $actor);

        // Assert — the order is back and its ledger is not. Two rows, both of them history.
        $this->assertFalse($restored->trashed());
        $this->assertSame('0.00', (string) $restored->paid_amount);
        $this->assertCount(1, $this->reversalsOn($restored));
        $this->assertSame(2, OrderPayment::query()->where('order_id', $restored->getKey())->count());
    }

    public function test_a_refund_is_left_alone_because_it_is_not_a_credit(): void
    {
        // Arrange — money that genuinely went back out. Reversing it would claim it never did.
        $order = $this->deliveredOrder();
        $actor = $this->actor();

        $payment = app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '120', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );

        $refund = new OrderPayment([
            'amount' => '20.00',
            'method' => PaymentMethod::Cash,
            'paid_at' => now(),
        ]);
        $refund->order_id = $order->getKey();
        $refund->type = OrderPaymentType::Refund;
        $refund->recorded_by = $actor->getKey();
        $refund->save();

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — the payment is reversed, the refund is not touched
        $reversals = $this->reversalsOn($deleted);

        $this->assertCount(1, $reversals);
        $this->assertArrayHasKey((int) $payment->getKey(), $reversals);
        $this->assertArrayNotHasKey((int) $refund->getKey(), $reversals);
    }

    // ------------------------------------- the investors' earnings in §٢٫١

    public function test_deleting_a_delivered_order_takes_the_deal_earnings_back_out(): void
    {
        // Arrange — 1,000 units at 2.000 financed by a deal, sold at 5.000 apiece and delivered,
        // which posts the investor's half of the 3,000 profit into his wallet.
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $actor = $this->actor();
        $actor->givePermissionTo([
            PermissionName::MoveOrderToReadyToPrint->value,
            PermissionName::MoveOrderToPrinting->value,
            PermissionName::MoveOrderToReady->value,
            PermissionName::DispatchOrders->value,
            PermissionName::MarkOrdersDelivered->value,
        ]);

        [$size, $warehouse, $deal, $investor] = $this->fundedShelf($actor);

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

        $this->walkToDelivered($order, $warehouse, $actor);

        $earning = InvestorWalletEntry::query()
            ->where('investor_id', $investor->getKey())
            ->where('type', WalletEntryType::Profit->value)
            ->sole();
        $this->assertSame('1500.00', (string) $earning->amount);

        // Act
        app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert — the earning is undone by a reversal pointing at it, not by an edited row, and
        // nothing new stands for this order.
        $this->assertSame(1, InvestorWalletEntry::query()
            ->where('type', WalletEntryType::Reversal->value)
            ->where('reverses_entry_id', $earning->getKey())
            ->count());

        $this->assertSame(0, InvestorWalletEntry::query()
            ->where('source_id', $order->getKey())
            ->whereIn('type', [WalletEntryType::Profit->value, WalletEntryType::Loss->value])
            ->whereDoesntHave('reversedBy')
            ->count());
    }

    // ------------------------------------------- the confirmation in §٧٫١

    public function test_the_delete_preview_puts_the_money_before_the_stock(): void
    {
        // Arrange — the figure §٧٫١ uses as its own example
        $order = $this->deliveredOrder('1200.00');
        $actor = $this->actor();

        app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '1200', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );

        // Act
        $preview = StockEffectPreview::for($order->refresh());

        // Assert — the order of the two sections is the server's decision, not Dart's, so it is
        // asserted on the key order of the envelope itself.
        $this->assertSame(['money', 'stock'], array_keys($preview));

        $this->assertSame('reverse', $preview['money']['kind']);
        $this->assertSame('سيُعكس ما قُبض على هذه الطلبية:', $preview['money']['warning']);
        $this->assertSame(
            [['label' => 'مدفوع', 'amount' => '1200.00', 'currency' => 'د.ل']],
            $preview['money']['lines'],
        );

        // The sentence the whole section exists for.
        $this->assertStringContainsString('الاستعادة لا تُعيدها', (string) $preview['money']['note']);
        $this->assertStringContainsString('بيدٍ', (string) $preview['money']['note']);
    }

    public function test_the_money_preview_names_each_kind_with_its_own_total(): void
    {
        // Arrange — two payments, one write-off and one carrier settlement
        $order = $this->deliveredOrder();
        $actor = $this->actor();

        app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '70', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );
        app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '50', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );
        app(RecordCarrierSettlement::class)($order->refresh(), '30', 'تحصيل نورس', $actor);
        app(WriteOffOrderBalance::class)($order->refresh(), '50', 'خصم مجاملة', $actor);

        // Act
        $preview = StockEffectPreview::for($order->refresh());

        // Assert — one line per kind, summed, in the server's order
        $this->assertSame(
            [
                ['label' => 'مدفوع', 'amount' => '120.00', 'currency' => 'د.ل'],
                ['label' => 'إعفاء', 'amount' => '50.00', 'currency' => 'د.ل'],
                ['label' => 'تحصيل مندوب', 'amount' => '30.00', 'currency' => 'د.ل'],
            ],
            $preview['money']['lines'],
        );
    }

    public function test_an_order_with_no_live_payments_gets_no_money_section_at_all(): void
    {
        // Arrange — §٧٫١: not an empty section, not a «لا يوجد» line. Nothing.
        $order = $this->deliveredOrder();
        $actor = $this->actor();

        $payment = app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '120', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );
        app(ReverseOrderPayment::class)($order->refresh(), $payment, 'أُدخلت على الطلبية الخطأ', $actor);

        // Act
        $preview = StockEffectPreview::for($order->refresh());

        // Assert
        $this->assertNull($preview['money']);
        $this->assertSame('none', $preview['stock']['kind']);
    }

    public function test_the_restore_preview_says_the_reversed_payments_do_not_come_back(): void
    {
        // Arrange
        $order = $this->deliveredOrder();
        $actor = $this->actor();

        app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '120', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Act
        $preview = StockEffectPreview::for($deleted);

        // Assert — the matching line §٧٫١ asks for, so nobody waits for the money to reappear
        $this->assertSame('reversed', $preview['money']['kind']);
        $this->assertSame('الدفعات المعكوسة لا تعود', $preview['money']['warning']);
        $this->assertSame([], $preview['money']['lines']);
    }

    public function test_a_deleted_order_that_never_carried_money_gets_no_money_section(): void
    {
        // Arrange
        $order = $this->deliveredOrder();
        $actor = $this->actor();
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Act
        $preview = StockEffectPreview::for($deleted);

        // Assert
        $this->assertNull($preview['money']);
    }

    public function test_the_preview_figure_is_the_one_the_delete_will_actually_write(): void
    {
        // Arrange — the point of building both from the same ledger: what is shown and what is
        // written are the same number, and this pins them together.
        $order = $this->deliveredOrder();
        $actor = $this->actor();

        app(RecordOrderPayment::class)(
            $order->refresh(),
            OrderPaymentData::fromArray(['amount' => '120', 'method' => PaymentMethod::Cash->value]),
            $actor,
        );
        app(RecordCarrierSettlement::class)($order->refresh(), '30', 'تحصيل نورس', $actor);

        $preview = StockEffectPreview::for($order->refresh());

        // Act
        $deleted = app(DeleteOrder::class)($order->refresh(), $actor);

        // Assert
        $shown = array_sum(array_map(
            fn (array $line): float => (float) $line['amount'],
            $preview['money']['lines'],
        ));
        $written = array_sum(array_map('floatval', array_values($this->reversalsOn($deleted))));

        $this->assertSame(150.0, $shown);
        $this->assertSame($shown, $written);
    }

    // -------------------------------------------- the stock lines in §٧ / 8

    public function test_a_stock_line_names_the_product_as_well_as_the_size(): void
    {
        // Arrange — two products in the same size, which is the case that rendered twice as
        // «25*35» on the one screen the whole feature exists to show.
        $actor = $this->actor();
        $warehouse = Warehouse::factory()->create();

        $bags = Product::factory()->create(['name' => 'كيس ورقي']);
        $sacks = Product::factory()->create(['name' => 'كيس بلاستيك']);
        $bagSize = ProductVariant::factory()->create(['product_id' => $bags->getKey(), 'label' => '25*35']);
        $sackSize = ProductVariant::factory()->create(['product_id' => $sacks->getKey(), 'label' => '25*35']);

        $this->stockUp($warehouse, $bagSize, '500', $actor);
        $this->stockUp($warehouse, $sackSize, '500', $actor);

        $order = Order::factory()->create();
        foreach ([$bagSize, $sackSize] as $size) {
            OrderItem::factory()->for($order)->create([
                'product_id' => $size->product_id,
                'product_variant_id' => $size->getKey(),
                'product_name' => $size->product->name,
                'variant_label' => $size->label,
                'quantity' => '300.000',
            ]);
        }

        app(ChangeOrderStatus::class)(
            $order->refresh(),
            OrderStatus::ReadyToPrint,
            null,
            $actor,
            ['warehouse_id' => $warehouse->getKey()],
        );

        // Act
        $preview = StockEffectPreview::for($order->refresh());

        // Assert — two rows a person can tell apart, and a count that reads like a count
        $labels = array_column($preview['stock']['lines'], 'label');

        $this->assertSame(['كيس ورقي — 25*35', 'كيس بلاستيك — 25*35'], $labels);
        $this->assertSame('300', $preview['stock']['lines'][0]['quantity']);
    }

    /** Puts `$quantity` of `$variant` on `$warehouse`'s shelf. */
    private function stockUp(Warehouse $warehouse, ProductVariant $variant, string $quantity, User $actor): void
    {
        app(InventoryService::class)->recordMovement(StockMovementData::arrival([
            'stock_item_id' => $variant->stock_item_id,
            'to_warehouse_id' => $warehouse->getKey(),
            'quantity' => $quantity,
            'unit_cost' => '2.000',
        ], (int) $actor->getKey()));
    }

    /**
     * A shelf of 1,000 at 2.000 financed wholly by one deal, whose single investor holds all of
     * it and takes half the profit.
     *
     * @return array{0: ProductVariant, 1: Warehouse, 2: InvestorDeal, 3: Investor}
     */
    private function fundedShelf(User $actor): array
    {
        $category = ProductCategory::factory()->create(['is_investable' => true]);
        $product = Product::factory()->create([
            'pricing_unit' => PricingUnit::Piece,
            'product_category_id' => $category->getKey(),
            'is_active' => true,
        ]);
        $size = ProductVariant::factory()->for($product)->create([
            'label' => '25*35',
            'stock_item_id' => StockItem::factory()->unit(PricingUnit::Piece)->create()->getKey(),
        ]);

        $investor = Investor::factory()->create();
        $deal = InvestorDeal::factory()->open()->create(['investor_profit_share_percent' => '50.00']);

        InvestorDealItem::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'stock_item_id' => $size->stock_item_id,
        ]);
        InvestorDealShare::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'investor_id' => $investor->getKey(),
            'share_percent' => '100.0000',
            'committed_amount' => '30000.00',
        ]);

        $warehouse = Warehouse::factory()->create();

        app(InventoryService::class)->recordMovement(StockMovementData::arrival([
            'stock_item_id' => $size->stock_item_id,
            'to_warehouse_id' => $warehouse->getKey(),
            'quantity' => '1000',
            'unit_cost' => '2.000',
            'investor_deal_id' => $deal->getKey(),
        ], (int) $actor->getKey()));

        return [$size, $warehouse, $deal, $investor];
    }

    private function walkToDelivered(Order $order, Warehouse $warehouse, User $actor): void
    {
        app(ChangeOrderStatus::class)(
            $order->refresh(),
            OrderStatus::ReadyToPrint,
            null,
            $actor,
            ['warehouse_id' => $warehouse->getKey()],
        );
        app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Printing, null, $actor);
        app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Ready, null, $actor);
        app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::OutForDelivery, null, $actor);
        app(ChangeOrderStatus::class)($order->refresh(), OrderStatus::Delivered, null, $actor);
    }
}
