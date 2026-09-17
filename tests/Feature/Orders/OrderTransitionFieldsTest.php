<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Delivery\Models\ShippingCompany;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Order\DTOs\TransitionField;
use App\Domain\Order\Enums\DesignSource;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderDesign;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Order\Support\TransitionFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What each move *asks for*, and what happens when it is given.
 *
 * **The app draws the form from `available_transitions[].fields`.** So a move that needs
 * artwork says so in the same payload that offers it, and adding a field to a move later is a
 * change here rather than an app release. This test is the contract: it pins what is described,
 * that the description and the validation cannot disagree, and that the whole move — the
 * artwork and the status — lands in one transaction or not at all.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderTransitionFieldsTest extends TestCase
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
            PermissionName::ManageOrderDesigns->value,
            PermissionName::MoveOrderToReadyToPrint->value,
            PermissionName::MoveOrderToDesigning->value,
            PermissionName::MoveOrderToPrinting->value,
            PermissionName::MoveOrderToReady->value,
            PermissionName::MoveOrderToShortage->value,
            PermissionName::CancelOrders->value,
            // `ready` now deducts stock for real, so a foreman occasionally needs to seed a
            // balance to test against.
            PermissionName::ManageInventory->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * Somebody who agrees the money, and nothing else.
     *
     * @return array<string, string>
     */
    private function accountant(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::SettleOrders->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * Somebody who sends parcels out.
     *
     * @return array<string, string>
     */
    private function dispatcher(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewOrders->value,
            PermissionName::DispatchOrders->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function show(array $headers, Order $order): TestResponse
    {
        return $this->withHeaders($headers)->getJson("/api/v1/orders/{$order->id}");
    }

    private function move(array $headers, Order $order, OrderStatus $to, array $payload = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/status",
            ['status' => $to->value] + $payload,
        );
    }

    /**
     * An order with the bags handed over and a given amount of its invoice collected.
     *
     * `paid_amount` is written directly rather than through the payments endpoint — the same
     * shortcut `OrderPaymentStatusFilterTest` takes, and for the same reason: what is under test
     * here is the guard that *reads* the figure, not the ledger that writes it.
     */
    private function deliveredOrder(string $grandTotal, string $paid): Order
    {
        return Order::factory()->status(OrderStatus::Delivered)->create([
            'grand_total' => $grandTotal,
            'paid_amount' => $paid,
        ]);
    }

    /**
     * @return array{0: Order, 1: Customer}
     */
    private function orderNeedingArtwork(): array
    {
        $customer = Customer::factory()->create();

        // **Standing at the handover, which is where the designer picks an order up.** It used to
        // be «جديدة»: the press was reached straight from intake, so that was the status these
        // moves started from. An order now crosses to the printing department through «جاهزة
        // للطباعة», and «قيد التصميم» is one of the two doors out of it.
        $order = Order::factory()->forCustomer($customer)->status(OrderStatus::ReadyToPrint)->create([
            'design_source' => DesignSource::Customer,
        ]);

        return [$order, $customer];
    }

    /** The one transition out of the list, whatever position the map put it in. */
    private function transition(TestResponse $response, OrderStatus $target): ?array
    {
        $transitions = $response->json('data.available_transitions');

        foreach ($transitions as $transition) {
            if ($transition['status'] === $target->value) {
                return $transition;
            }
        }

        return null;
    }

    // ───────────────────────────── what a move describes ─────────────────────────────

    public function test_moving_to_designing_offers_artwork_without_demanding_it(): void
    {
        // Arrange
        [$order] = $this->orderNeedingArtwork();
        $headers = $this->foreman();

        // Act
        $designing = $this->transition($this->show($headers, $order), OrderStatus::Designing);

        // Assert — the app renders this and nothing it wrote itself. The note is last and comes
        // with every move; the artwork is offered here because this is the move it usually
        // arrives with, and left optional because the queue exists for the orders it has not been
        // drawn for yet.
        $this->assertSame([
            [
                'key' => 'design_ids',
                'type' => 'customer_designs',
                'label' => 'التصاميم',
                'required' => false,
                'multiple' => true,
                'multiline' => false,
                'hint' => 'تُرفع إلى مكتبة العميل ثم تُربط بالطلبية',
                'min' => null,
                'max' => null,
                'value' => null,
                'value_label' => null,
                'options' => [],
                'required_with' => null,
                'required_if' => null,
                'extensions' => [],
                'max_kilobytes' => null,
            ],
            [
                'key' => 'reason',
                'type' => 'text',
                'label' => 'ملاحظة',
                'required' => false,
                'multiple' => false,
                'multiline' => true,
                'hint' => 'تُسجَّل في سجل الطلبية',
                'min' => null,
                'max' => null,
                'value' => null,
                'value_label' => null,
                'options' => [],
                'required_with' => null,
                'required_if' => null,
                'extensions' => [],
                'max_kilobytes' => null,
            ],
        ], $designing['fields']);
    }

    public function test_even_a_reprint_is_offered_artwork_on_the_way_into_design(): void
    {
        // Arrange — `design_source = none` says whose work the artwork was, which is a question
        // about money. Whether there is a file to look at is a different question. At the
        // handover, because that is where «قيد التصميم» is reached from.
        $order = Order::factory()->status(OrderStatus::ReadyToPrint)->create([
            'design_source' => DesignSource::None,
        ]);
        $headers = $this->foreman();

        // Act
        $designing = $this->transition($this->show($headers, $order), OrderStatus::Designing);

        // Assert — a reprint may never enter design at all; if it does, the field is there for
        // whatever the customer sent, on the same terms as every other order.
        $this->assertSame('design_ids', $designing['fields'][0]['key']);
        $this->assertFalse($designing['fields'][0]['required']);
    }

    public function test_a_new_order_starts_work_or_says_it_cannot_and_is_never_cancelled(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();

        // Act
        $offered = array_column(
            $this->show($headers, $order)->json('data.available_transitions'),
            'status',
        );

        // Assert — the handover to the press, and «نواقص» for the case where the job cannot be
        // started at all. **Which half of the press it goes to is no longer asked here**: an
        // order crosses to the printing department through one door, and that department chooses
        // between designing and printing once it has it. Cancelling is not among them either: a
        // job nobody has begun is two taps from being begun, and an ending competed with the
        // moves that matter.
        $this->assertSame(
            [
                OrderStatus::ReadyToPrint->value,
                OrderStatus::Shortage->value,
            ],
            $offered,
        );
    }

    public function test_an_order_that_already_carries_artwork_is_not_asked_again(): void
    {
        // Arrange
        [$order, $customer] = $this->orderNeedingArtwork();
        $design = CustomerDesign::factory()->for($customer)->create();
        OrderDesign::factory()->for($order)->create(['customer_design_id' => $design->id]);
        $headers = $this->foreman();

        // Act — the correction path: printing back to designing, where versions already exist.
        $designing = $this->transition($this->show($headers, $order), OrderStatus::Designing);

        // Assert — still offered, because another version is normal; no longer demanded.
        $this->assertFalse($designing['fields'][0]['required']);
    }

    public function test_cancelling_asks_for_its_reason_as_a_field(): void
    {
        // Arrange — from printing, because «جديدة» has no cancellation to offer.
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->foreman();

        // Act
        $cancelled = $this->transition($this->show($headers, $order), OrderStatus::Cancelled);

        // Assert — one way to render every input, instead of a special case written in Dart.
        $this->assertTrue($cancelled['requires_reason']);
        $this->assertSame('reason', $cancelled['fields'][0]['key']);
        $this->assertSame('text', $cancelled['fields'][0]['type']);
        $this->assertTrue($cancelled['fields'][0]['required']);
    }

    public function test_printing_straight_from_the_handover_carries_the_artwork(): void
    {
        // Arrange — the order never entered the design conversation and does not need to: the
        // customer brought the file with them, and it is standing at the handover.
        [$order] = $this->orderNeedingArtwork();
        $headers = $this->foreman();

        // Act
        $printing = $this->transition($this->show($headers, $order), OrderStatus::Printing);

        // Assert — «جاهزة للطباعة» accepts a version, so the move that leaves it may carry one.
        // **This is the whole short path**, and it had to survive the handover being inserted in
        // front of it: an agreed file goes on the order and the press starts, with no detour
        // through a status naming work nobody did. The warehouse is not named here — the stock
        // left at the handover, not on the way into printing.
        $this->assertSame(['design_ids', 'reason'], array_column($printing['fields'], 'key'));
        $this->assertFalse($printing['fields'][0]['required']);
        $this->assertFalse($printing['fields'][1]['required']);
    }

    public function test_leaving_design_for_the_press_offers_the_artwork_one_last_time(): void
    {
        // Arrange — the order has been waiting in the designer's queue.
        [$order] = $this->orderNeedingArtwork();
        $order->forceFill(['status' => OrderStatus::Designing])->save();
        $headers = $this->foreman();

        // Act
        $printing = $this->transition($this->show($headers, $order), OrderStatus::Printing);

        // Assert — this is the move the finished artwork arrives with: «قيد التصميم» is the one
        // status that accepts a version, and this is the last moment the order stands in it.
        $this->assertSame(['design_ids', 'reason'], array_column($printing['fields'], 'key'));
        $this->assertFalse($printing['fields'][0]['required']);
    }

    public function test_reaching_ready_asks_for_a_warehouse(): void
    {
        // Arrange — a run about to be shelved, stock never yet deducted for it.
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);

        // Assert — the same move that empties a warehouse names it, required because stock has
        // never left one for this order yet.
        $this->assertSame('warehouse_id', $ready['fields'][0]['key']);
        $this->assertSame('warehouse', $ready['fields'][0]['type']);
        $this->assertTrue($ready['fields'][0]['required']);
    }

    public function test_stock_already_deducted_is_not_asked_for_a_warehouse_again(): void
    {
        // Arrange — the ordinary printed order: its stock left at «جاهزة للطباعة», and it is now
        // at the press on its way to «جاهزة».
        $order = Order::factory()->status(OrderStatus::Printing)->create([
            'stock_deducted_at' => now(),
        ]);
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);

        // Assert — **withheld outright, not offered-and-optional as it once was.** An order
        // arriving here already deducted is being *corrected*, and a correction goes back to the
        // shelf the goods came off — `orders.fulfillment_warehouse_id`. A second picker could only
        // ever send the difference somewhere else and leave two shelves wrong instead of one.
        $this->assertNotContains('warehouse_id', array_column($ready['fields'], 'key'));
    }

    /**
     * **«يُخصم منه ما تستهلكه» does not say how much, and the person tapping it cannot know.**
     *
     * What leaves the shelf is `warehouse_quantity ?? quantity` per line, in the *shelf's* own
     * unit — see `OrderItem::stockUnit()` — which need not be the unit the line is sold in. A
     * foreman moving an order to «جاهزة» was being asked to name a warehouse without being told
     * what was about to come out of it, and the two numbers can differ both in size and in kind:
     * 300 bags sold, 12.5 kilograms taken.
     *
     * Said in the hint rather than as a new field, because it is not an input — and because the
     * app draws whatever the server hands it, so this costs the client nothing.
     */
    public function test_the_warehouse_hint_names_what_will_actually_be_deducted(): void
    {
        // Arrange — sold by the piece, stocked by the kilo, and weighed onto the order at intake.
        $product = Product::factory()->create(['pricing_unit' => PricingUnit::Piece]);
        $shelf = StockItem::factory()->unit(PricingUnit::Kilogram)->create();
        $variant = ProductVariant::factory()->for($product)->create([
            'label' => '25*35',
            'stock_item_id' => $shelf->id,
        ]);
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'variant_label' => '25*35',
            'quantity' => '300',
            'warehouse_quantity' => '12.5',
            'pricing_unit' => PricingUnit::Piece,
        ]);
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);

        // Assert — the size, the figure that will leave, and the unit it leaves in. Not 300.
        $hint = $ready['fields'][0]['hint'];

        $this->assertStringContainsString('25*35', $hint);
        $this->assertStringContainsString('12.5', $hint);
        $this->assertStringContainsString(PricingUnit::Kilogram->label(), $hint);
        $this->assertStringNotContainsString('300', $hint);
    }

    public function test_a_line_never_weighed_is_named_at_its_ordered_quantity(): void
    {
        // Arrange — no `warehouse_quantity`, which is nine lines in ten: what is sold is what
        // leaves, and `producedQuantity()` falls back to the ordered figure.
        $product = Product::factory()->create(['pricing_unit' => PricingUnit::Piece]);
        $shelf = StockItem::factory()->unit(PricingUnit::Piece)->create();
        $variant = ProductVariant::factory()->for($product)->create([
            'label' => '45*50',
            'stock_item_id' => $shelf->id,
        ]);
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'variant_label' => '45*50',
            'quantity' => '200',
            'warehouse_quantity' => null,
            'pricing_unit' => PricingUnit::Piece,
        ]);
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);

        // Assert
        $hint = $ready['fields'][0]['hint'];

        $this->assertStringContainsString('45*50', $hint);
        $this->assertStringContainsString('200', $hint);
        $this->assertStringContainsString(PricingUnit::Piece->label(), $hint);
    }

    public function test_an_order_whose_stock_already_left_is_shown_no_deduction_preview(): void
    {
        // Arrange — listing what "will" be deducted for an order that already deducted would be
        // describing an event in the future tense after it happened.
        [$order, $item] = $this->lineStockedIn(PricingUnit::Kilogram, measured: '12.5');
        $order->forceFill(['stock_deducted_at' => now()])->save();
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);
        $field = $this->fieldNamed($ready['fields'], "warehouse_quantity_{$item->getKey()}");

        // Assert — the preview went with the warehouse picker: both belong to the move that
        // *takes* the stock, and this move corrects a draw that already happened. What is said
        // instead is what actually left, so the foreman is correcting a figure rather than
        // guessing at one.
        $this->assertNotContains('warehouse_id', array_column($ready['fields'], 'key'));
        $this->assertStringContainsString('خرج من المخزن', $field['hint']);
        $this->assertStringContainsString('12.5', $field['hint']);
    }

    // ────────────── what actually leaves the shelf, asked line by line ──────────────

    /**
     * One line of a run being printed: sold by the piece, off a shelf counted in $stockUnit.
     *
     * @return array{0: Order, 1: OrderItem}
     */
    private function lineStockedIn(
        PricingUnit $stockUnit,
        string $sold = '500',
        ?string $measured = null,
    ): array {
        $product = Product::factory()->create(['pricing_unit' => PricingUnit::Piece]);
        $shelf = StockItem::factory()->unit($stockUnit)->create();
        $variant = ProductVariant::factory()->for($product)->create([
            'label' => '25*35',
            'stock_item_id' => $shelf->id,
        ]);
        $order = Order::factory()->status(OrderStatus::Printing)->create();

        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'variant_label' => '25*35',
            'quantity' => $sold,
            'warehouse_quantity' => $measured,
            'pricing_unit' => PricingUnit::Piece,
        ]);

        return [$order, $item];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<string, mixed>|null
     */
    private function fieldNamed(array $fields, string $key): ?array
    {
        return collect($fields)->firstWhere('key', $key);
    }

    /**
     * **A bag sold by the piece and stocked by the kilo cannot be deducted automatically.**
     *
     * «٥٠٠ قطعة» is what the customer agreed and what the invoice is written from; the shelf it
     * comes off is counted in kilograms, and there is no factor that turns one into the other —
     * bags of one size vary in weight, which is the whole reason that shelf is weighed instead
     * of counted. What the code did until now was take the *sold* figure and write it against a
     * kilogram shelf: five hundred pieces left as five hundred kilograms, and no screen said so.
     *
     * So the move that empties the shelf asks — once, per line, in the shelf's own unit — and
     * will not go through without an answer.
     */
    public function test_a_line_stocked_in_another_unit_asks_what_leaves_the_shelf(): void
    {
        // Arrange — 500 pieces agreed with the customer, kilograms on the shelf.
        [$order, $item] = $this->lineStockedIn(PricingUnit::Kilogram, sold: '500');
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);
        $field = $this->fieldNamed($ready['fields'], "warehouse_quantity_{$item->getKey()}");

        // Assert — a number, demanded, and **the unit is in the label**: the person typing is
        // holding two figures and the box has to say which of them it wants.
        $this->assertSame('number', $field['type']);
        $this->assertTrue($field['required']);
        $this->assertStringContainsString('25*35', $field['label']);
        $this->assertStringContainsString(PricingUnit::Kilogram->label(), $field['label']);

        // …and the hint carries what was sold, because that is what they are converting from.
        $this->assertStringContainsString('500', $field['hint']);
        $this->assertStringContainsString(PricingUnit::Piece->label(), $field['hint']);
    }

    public function test_a_line_stocked_in_its_selling_unit_is_not_asked_at_all(): void
    {
        // Arrange — nine lines in ten: pieces sold, pieces on the shelf.
        [$order, $item] = $this->lineStockedIn(PricingUnit::Piece);
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);

        // Assert — what was sold is what leaves, and a box asking a foreman to retype a number
        // the order already holds is a box that can only ever introduce a difference.
        $this->assertNull($this->fieldNamed($ready['fields'], "warehouse_quantity_{$item->getKey()}"));
    }

    public function test_a_line_already_weighed_opens_holding_that_figure(): void
    {
        // Arrange — an order taken while the create form still asked for this carries the number
        // somebody measured then.
        [$order, $item] = $this->lineStockedIn(PricingUnit::Kilogram, measured: '12.5');
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);
        $field = $this->fieldNamed($ready['fields'], "warehouse_quantity_{$item->getKey()}");

        // Assert — an answer, not a placeholder: re-asking from an empty box invites a second,
        // different figure for one parcel that was weighed once.
        $this->assertSame('12.5', $field['value']);
    }

    public function test_the_shelf_quantity_is_still_demanded_once_stock_has_left(): void
    {
        // Arrange — stock already gone at «جاهزة للطباعة». This move is the *correction*.
        [$order, $item] = $this->lineStockedIn(PricingUnit::Kilogram);
        $order->forceFill(['stock_deducted_at' => now()])->save();
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);
        $field = $this->fieldNamed($ready['fields'], "warehouse_quantity_{$item->getKey()}");

        // Assert — **required, and it did not used to be.** The warehouse weighed what it pulled;
        // the press knows what the run actually used, and that second figure is the true one. An
        // emptied box would read as «صفر» against a shelf that has already given the goods up, so
        // the field opens holding what left and asks to be confirmed or corrected.
        $this->assertTrue($field['required']);
    }

    public function test_an_unweighed_line_is_not_previewed_as_a_figure_nobody_measured(): void
    {
        // Arrange — 500 pieces, a kilogram shelf, nothing weighed yet. The preview printed
        // «500.000 كجم» here, which is precisely the confusion the field below it closes.
        [$order, $item] = $this->lineStockedIn(PricingUnit::Kilogram, sold: '500');
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);
        $hint = $this->fieldNamed($ready['fields'], 'warehouse_id')['hint'];

        // Assert — the size is still named, but the figure is the one being asked for below
        // rather than a sold quantity wearing a unit it was never measured in.
        $this->assertStringContainsString('25*35', $hint);
        $this->assertStringNotContainsString('500.000 '.PricingUnit::Kilogram->label(), $hint);
        $this->assertStringContainsString('أدناه', $hint);
    }

    // ──────────────────────── what «جاري التوصيل» asks for ────────────────────────

    public function test_sending_a_parcel_out_names_the_carrier(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->dispatcher();

        // Act
        $out = $this->transition($this->show($headers, $order), OrderStatus::OutForDelivery);
        $fields = collect($out['fields']);

        // Assert — the company is compulsory and the driver is not: the company is answerable
        // for the parcel, while the man carrying it is merely useful to be able to ring, and
        // often nobody has his number at the moment it leaves.
        $carrier = $fields->firstWhere('key', 'shipping_company_id');
        $this->assertNotNull($carrier);
        $this->assertSame('shipping_company', $carrier['type']);
        $this->assertTrue($carrier['required']);

        $courier = $fields->firstWhere('key', 'courier_phone');
        $this->assertNotNull($courier);
        $this->assertSame('هاتف المندوب', $courier['label']);
        $this->assertFalse($courier['required']);
    }

    public function test_the_dispatch_form_opens_holding_the_default_carrier(): void
    {
        // Arrange — the business named the one that takes nearly every parcel.
        ShippingCompany::factory()->create(['name' => 'درب']);
        $preferred = ShippingCompany::factory()->asDefault()->create(['name' => 'النورس']);
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->dispatcher();

        // Act
        $out = $this->transition($this->show($headers, $order), OrderStatus::OutForDelivery);
        $carrier = $this->fieldNamed($out['fields'], 'shipping_company_id');

        // Assert — the id is what travels back, and the name travels beside it so the app can
        // write «النورس» on the button without having fetched the list first.
        $this->assertSame((string) $preferred->id, $carrier['value']);
        $this->assertSame('النورس', $carrier['value_label']);
    }

    public function test_the_dispatch_form_opens_empty_when_nobody_named_a_default(): void
    {
        // Arrange
        ShippingCompany::factory()->count(2)->create();
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->dispatcher();

        // Act
        $out = $this->transition($this->show($headers, $order), OrderStatus::OutForDelivery);
        $carrier = $this->fieldNamed($out['fields'], 'shipping_company_id');

        // Assert — which of two took the parcel is a fact, not a default.
        $this->assertNull($carrier['value']);
        $this->assertNull($carrier['value_label']);
    }

    public function test_a_default_we_stopped_dealing_with_is_not_suggested(): void
    {
        // Arrange — the flag cannot outlive the dealing, but a row edited outside the app could
        // still carry it.
        ShippingCompany::factory()->inactive()->create(['name' => 'النورس', 'is_default' => true]);
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->dispatcher();

        // Act
        $out = $this->transition($this->show($headers, $order), OrderStatus::OutForDelivery);
        $carrier = $this->fieldNamed($out['fields'], 'shipping_company_id');

        // Assert — the picker would refuse it, so the form must not open holding it.
        $this->assertNull($carrier['value']);
    }

    public function test_an_order_the_customer_is_collecting_is_asked_for_no_carrier(): void
    {
        // Arrange — a branch order. The clerk still presses one dispatch button.
        $order = Order::factory()->officePickup()->status(OrderStatus::Ready)->create();
        $headers = $this->dispatcher();

        // Act
        $dispatch = $this->transition($this->show($headers, $order), OrderStatus::OfficePickup);

        // Assert — nobody carries a parcel the customer is coming to fetch. The description
        // resolves the dispatch pair exactly as the move does, so what is asked for and what
        // is accepted cannot disagree.
        $this->assertSame(['reason'], array_column($dispatch['fields'], 'key'));
    }

    public function test_a_parcel_cannot_leave_with_nobody_named(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->dispatcher();

        // Act
        $response = $this->move($headers, $order, OrderStatus::OutForDelivery);

        // Assert — a parcel on the road with no carrier recorded is a parcel nobody can chase,
        // and the return chain has nothing to be answered from.
        $response->assertStatus(422)->assertJsonValidationErrors('fields.shipping_company_id');
        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    public function test_the_carrier_is_written_down_by_name_as_well_as_by_key(): void
    {
        // Arrange
        $company = ShippingCompany::factory()->create(['name' => 'درب']);
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->dispatcher();

        // Act
        $response = $this->move($headers, $order, OrderStatus::OutForDelivery, [
            'fields' => ['shipping_company_id' => $company->id, 'courier_phone' => '0913334444'],
        ]);

        // Assert — the key for filtering and for opening the record, the name for what the
        // order said on the day. Renaming the company later must not rewrite this order.
        $response->assertOk();

        $dispatched = $order->fresh();
        $this->assertSame($company->id, $dispatched->shipping_company_id);
        $this->assertSame('درب', $dispatched->shipping_company);
        $this->assertSame('0913334444', $dispatched->courier_phone);

        $company->update(['name' => 'درب للشحن السريع']);
        $this->assertSame('درب', $order->fresh()->shipping_company);
    }

    public function test_a_carrier_removed_from_the_list_cannot_be_chosen(): void
    {
        // Arrange
        $company = ShippingCompany::factory()->create();
        $company->delete();
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $headers = $this->dispatcher();

        // Act
        $response = $this->move($headers, $order, OrderStatus::OutForDelivery, [
            'fields' => ['shipping_company_id' => $company->id],
        ]);

        // Assert — the rule agrees with the picker: what is not offered is not accepted.
        $response->assertStatus(422)->assertJsonValidationErrors('fields.shipping_company_id');
    }

    // ──────────────────────── what «تم التسوية» asks for ────────────────────────

    public function test_settling_no_longer_asks_for_a_figure_nothing_adds_up(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Delivered)->create(['grand_total' => '250.00']);
        $headers = $this->accountant();

        // Act
        $settling = $this->transition($this->show($headers, $order), OrderStatus::Settled);

        // Assert — «المبلغ المستلم» (`collected_amount`) is gone from the form. It asked the
        // right question and answered none of it: the number went into a column no total read,
        // so an order could carry «المدفوع ٥٠٠» and «المستلم فعلياً ٤٥٠» at once with nothing
        // able to say which was true. What replaced it writes a real ledger entry — see
        // {@see OrderTransitionPaymentTest}. The column stays for the orders written before this.
        $this->assertNull(collect($settling['fields'])->firstWhere('key', 'collected_amount'));
    }

    public function test_a_settlement_records_no_amount_of_its_own(): void
    {
        // Arrange
        $order = $this->deliveredOrder('250.00', paid: '250.00');
        $headers = $this->accountant();

        // Act — the ordinary case: the money came back and it was the right money.
        $response = $this->move($headers, $order, OrderStatus::Settled);

        // Assert — nothing writes `collected_amount` any more, and the ledger says what came in.
        $response->assertOk()->assertJsonPath('data.status', 'settled');

        $settled = $order->fresh();
        $this->assertNull($settled->collected_amount);
        $this->assertNotNull($settled->settled_at);
    }

    public function test_the_retired_money_field_is_not_accepted_either(): void
    {
        // Arrange
        $order = $this->deliveredOrder('250.00', paid: '250.00');
        $headers = $this->accountant();

        // Act — a client written against the old form.
        $response = $this->move($headers, $order, OrderStatus::Settled, [
            'fields' => ['collected_amount' => '230.50'],
        ]);

        // Assert — refused rather than dropped in silence, which is the rule for every key this
        // move did not offer: swallowing it would teach the client it had been recorded.
        $response->assertStatus(422)->assertJsonValidationErrors('fields');
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_settling_is_its_own_grant(): void
    {
        // Arrange — a foreman runs the shop floor and touches no money.
        $order = Order::factory()->status(OrderStatus::Delivered)->create();

        // Act
        $response = $this->move($this->foreman(), $order, OrderStatus::Settled);

        // Assert
        $response->assertStatus(403);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    // ─────────────────── the money has to be in before the order is closed ───────────────────

    public function test_an_unpaid_order_cannot_be_settled(): void
    {
        // Arrange — «تم التسوية» and «غير مدفوعة» at once was the bug: an order can be walked to
        // the end of the line with nothing recorded against it.
        $order = $this->deliveredOrder('250.00', paid: '0.00');
        $headers = $this->accountant();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Settled);

        // Assert — refused, and the message names what is still owed, so the accountant is told
        // what to record rather than merely that the button did not work.
        $response->assertStatus(422);
        $this->assertStringContainsString('250.00', $response->json('message'));

        $unmoved = $order->fresh();
        $this->assertSame(OrderStatus::Delivered, $unmoved->status);
        $this->assertNull($unmoved->settled_at);
        $this->assertDatabaseMissing('order_status_transitions', [
            'order_id' => $order->id,
            'to_status' => OrderStatus::Settled->value,
        ]);
    }

    public function test_an_order_paid_only_in_part_cannot_be_settled(): void
    {
        // Arrange — the عربون case: something came in, not all of it.
        $order = $this->deliveredOrder('250.00', paid: '100.00');
        $headers = $this->accountant();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Settled);

        // Assert — the remainder is what is named, not the total.
        $response->assertStatus(422);
        $this->assertStringContainsString('150.00', $response->json('message'));
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_an_overpaid_order_may_still_be_settled(): void
    {
        // Arrange — a discount granted after the money came in. Nothing is owed on it, so the
        // guard has nothing to say: the rule is about money missing, not money matching.
        $order = $this->deliveredOrder('250.00', paid: '300.00');
        $headers = $this->accountant();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Settled);

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'settled');
    }

    public function test_an_order_that_costs_nothing_may_be_settled(): void
    {
        // Arrange — an office pickup with no delivery to charge for. Nothing is outstanding on a
        // total of zero, and refusing it would strand the order one step from the end.
        $order = $this->deliveredOrder('0.00', paid: '0.00');
        $headers = $this->accountant();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Settled);

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'settled');
    }

    // ─────────────────────── the note that travels with every move ───────────────────────

    public function test_every_move_can_carry_a_note(): void
    {
        // Arrange — one order sitting where several different moves are offered at once.
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        OrderItem::factory()->for($order)->create();
        $headers = $this->foreman();

        // Act
        $transitions = $this->show($headers, $order)->json('data.available_transitions');

        // Assert — no move is without one. Staff explain what they did in the moment they do it,
        // and a note nobody could leave is a note that ends up in a phone call instead.
        $this->assertNotEmpty($transitions);

        foreach ($transitions as $transition) {
            $note = collect($transition['fields'])->firstWhere('key', 'reason');

            $this->assertNotNull($note, "«{$transition['label']}» carries no note field");
            $this->assertTrue($note['multiline'], 'a note is a sentence, not a word');
        }
    }

    public function test_no_move_anywhere_in_the_machine_is_without_its_note(): void
    {
        // Arrange — the whole machine, not one order in one status: every status the business
        // has, and every move it is allowed to make from there. A line apiece, because two of
        // those moves ask their questions per line.
        $checked = 0;

        // Act & Assert — walked pair by pair, because the rule is about the pair. The backward
        // moves are the point of doing it this way: «قيد الطباعة» back to «قيد التصميم» is
        // exactly the move somebody needs to explain, and a rule pinned only on the way forward
        // would have let that one through.
        foreach (OrderStatus::cases() as $from) {
            $order = Order::factory()->status($from)->create();
            OrderItem::factory()->for($order)->create();

            foreach ($from->allowedNext() as $target) {
                $note = collect(TransitionFields::for($order->fresh(), $target))
                    ->first(fn (TransitionField $field) => $field->key === 'reason');

                $move = "{$from->label()} ← {$target->label()}";

                $this->assertNotNull($note, "«{$move}» carries no note field");
                $this->assertTrue($note->multiline, "«{$move}»: a note is a sentence, not a word");

                // Optional everywhere, and demanded in exactly two places — the two moves that
                // end an order against what the customer asked for. Asking for a sentence on
                // every move would fill the timeline with «تمام».
                //
                // **Listed here rather than read from `requiresReason()`**, which is what the
                // production code asks: a test that called the same method would agree with the
                // implementation by construction and notice nothing.
                $this->assertSame(
                    in_array($target, [OrderStatus::Cancelled, OrderStatus::RequestRejected], true),
                    $note->required,
                    "«{$move}» asks for the note on the wrong terms",
                );

                $checked++;
            }
        }

        // The walk itself has to have happened — a machine that offered nothing would have
        // passed every assertion above without making one.
        $this->assertGreaterThan(20, $checked);
    }

    public function test_only_writing_an_order_off_turns_that_note_into_an_explanation(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->foreman();

        // Act
        $shown = $this->show($headers, $order);
        $cancelling = $this->transition($shown, OrderStatus::Cancelled);
        $designing = $this->transition($shown, OrderStatus::Designing);

        $cancelNote = collect($cancelling['fields'])->firstWhere('key', 'reason');
        $designNote = collect($designing['fields'])->firstWhere('key', 'reason');

        // Assert — the same field, renamed and made compulsory exactly where an explanation is
        // owed. One input in the app, one column in the timeline.
        $this->assertSame('السبب', $cancelNote['label']);
        $this->assertTrue($cancelNote['required']);

        $this->assertSame('ملاحظة', $designNote['label']);
        $this->assertFalse($designNote['required']);
    }

    public function test_a_note_left_with_an_ordinary_move_is_kept_on_the_timeline(): void
    {
        // Arrange — stock already deducted, so `warehouse_id` is not demanded here too; this
        // test is about the note, not fulfillment.
        $order = Order::factory()->status(OrderStatus::Printing)->create(['stock_deducted_at' => now()]);
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Ready, [
            'fields' => ['reason' => 'الكمية طلعت زيادة ٢٠ كيس'],
        ]);

        // Assert — it lands in the same column a cancellation's reason does, so the order's
        // history has one place to read from rather than two.
        $response->assertOk();
        $this->assertDatabaseHas('order_status_transitions', [
            'order_id' => $order->id,
            'to_status' => OrderStatus::Ready->value,
            'reason' => 'الكمية طلعت زيادة ٢٠ كيس',
        ]);

        // And it stays a note about the move, not a reason the order was written off.
        $this->assertNull($order->fresh()->cancellation_reason);
    }

    // ───────────────────────────── what a move accepts ─────────────────────────────

    public function test_an_order_waits_in_design_before_there_is_anything_to_look_at(): void
    {
        // Arrange — the ordinary case: the customer wants something drawn, and nobody has drawn
        // it yet. There is nothing to attach.
        [$order] = $this->orderNeedingArtwork();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Designing);

        // Assert — this is what «قيد التصميم» is *for*: the order sits in the designer's queue
        // until the work exists. Demanding the file on the way in would have made the status
        // unreachable exactly when it is needed.
        $response->assertOk()->assertJsonPath('data.status', 'designing');
        $this->assertSame(0, $order->designs()->count());
    }

    public function test_the_artwork_and_the_move_arrive_together(): void
    {
        // Arrange
        [$order, $customer] = $this->orderNeedingArtwork();
        $designs = CustomerDesign::factory()->count(2)->for($customer)->create();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Designing, [
            'fields' => ['design_ids' => $designs->pluck('id')->all()],
        ]);

        // Assert — one request: the versions are numbered and the order has moved.
        $response->assertOk()->assertJsonPath('data.status', 'designing');
        $this->assertSame([1, 2], $order->designs()->pluck('version')->sort()->values()->all());
    }

    public function test_artwork_belonging_to_somebody_else_moves_nothing(): void
    {
        // Arrange
        [$order] = $this->orderNeedingArtwork();
        $stranger = CustomerDesign::factory()->create();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Designing, [
            'fields' => ['design_ids' => [$stranger->id]],
        ]);

        // Assert — the whole move rolls back: no half-designed order, no orphan version, and the
        // order left standing where it was.
        $response->assertStatus(422);
        $this->assertSame(OrderStatus::ReadyToPrint, $order->fresh()->status);
        $this->assertSame(0, $order->designs()->count());
    }

    public function test_a_field_the_move_never_offered_is_refused(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Printing, [
            'fields' => ['courier_name' => 'أحمد'],
        ]);

        // Assert — silently dropping it would teach a client that it worked.
        $response->assertStatus(422)->assertJsonValidationErrors('fields');
    }

    public function test_the_reason_may_arrive_inside_the_fields(): void
    {
        // Arrange
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        $headers = $this->foreman();

        // Act — the app sends every input the same way, including this one.
        $response = $this->move($headers, $order, OrderStatus::Cancelled, [
            'fields' => ['reason' => 'العميل غيّر رأيه'],
        ]);

        // Assert
        $response->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('العميل غيّر رأيه', $order->fresh()->cancellation_reason);
    }

    // ───────────────────────── what «قيد الطباعة» may and may not do ─────────────────────────

    public function test_the_artwork_is_settled_once_printing_starts(): void
    {
        // Arrange
        [$order, $customer] = $this->orderNeedingArtwork();
        $design = CustomerDesign::factory()->for($customer)->create();
        $order->forceFill(['status' => OrderStatus::Printing])->save();
        $headers = $this->foreman();

        // Act — adding a version straight onto an order that is being printed.
        $response = $this->withHeaders($headers)->postJson(
            "/api/v1/orders/{$order->id}/designs",
            ['customer_design_id' => $design->id],
        );

        // Assert — the press is running against the approved file. Changing the artwork means
        // sending the order back to «قيد التصميم», which is a move somebody makes on purpose.
        $response->assertStatus(422);
        $this->assertSame(0, $order->designs()->count());
    }

    public function test_sending_a_printing_order_back_to_design_still_carries_artwork(): void
    {
        // Arrange — the correction path, and the one case where a version arrives *while* the
        // order is still in a status that forbids adding one.
        [$order, $customer] = $this->orderNeedingArtwork();
        $design = CustomerDesign::factory()->for($customer)->create();
        $order->forceFill(['status' => OrderStatus::Printing])->save();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Designing, [
            'fields' => ['design_ids' => [$design->id]],
        ]);

        // Assert — the move and the version land together: the order is in design by the time
        // the artwork is attached, which is what makes the attachment legal.
        $response->assertOk()->assertJsonPath('data.status', 'designing');
        $this->assertSame(1, $order->designs()->count());
    }

    public function test_the_artwork_is_attached_while_the_order_is_still_in_design(): void
    {
        // Arrange — the whole point of the waiting: the designer finished, and sends the order
        // to the press with the file in the same hand. Stock already left a warehouse for this
        // run, so the move is not asked for one and this test stays about the artwork.
        [$order, $customer] = $this->orderNeedingArtwork();
        $design = CustomerDesign::factory()->for($customer)->create();
        $order->forceFill([
            'status' => OrderStatus::Designing,
            'stock_deducted_at' => now(),
        ])->save();
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Printing, [
            'fields' => ['design_ids' => [$design->id]],
        ]);

        // Assert — the mirror image of the correction path: there the status is written first
        // because the *new* status is the one that accepts artwork, here it is written last for
        // the same reason about the old one. Either way the version lands.
        $response->assertOk()->assertJsonPath('data.status', 'printing');
        $this->assertSame(1, $order->designs()->count());
    }

    public function test_the_order_says_which_of_the_two_may_still_be_changed(): void
    {
        // Arrange
        [$order] = $this->orderNeedingArtwork();
        $order->forceFill(['status' => OrderStatus::Printing])->save();
        $headers = $this->foreman();

        // Act
        $response = $this->show($headers, $order);

        // Assert — the app draws each section from these rather than keeping its own copy of
        // where the two lines fall, which are deliberately different lines.
        $response->assertJsonPath('data.items_are_editable', true)
            ->assertJsonPath('data.designs_are_editable', false);
    }

    // ────────────────────── the weight that used to be asked for ──────────────────────

    /**
     * **«جاهزة» no longer asks for a parcel weight, and there is nothing left that wanted one.**
     *
     * `orders.weight_kg` was written by this move and read by exactly two things: the API
     * resource that echoed it, and one fact row on the order screen. No invoice, no carrier, no
     * costing and no report ever computed anything from it — a field that was demanded of every
     * kilo-priced order on the grounds that «الوزن هو ما تُحاسب عليه» while the invoice was in
     * fact built from the line quantities, then and now. The column has since been dropped.
     *
     * What actually comes off the shelf is asked line by line instead, and only where nobody
     * could work it out — see the section above.
     */
    public function test_finishing_a_run_does_not_ask_for_a_parcel_weight(): void
    {
        // Arrange — a run sold by the kilo, which is the case that used to demand one.
        $product = Product::factory()->create(['pricing_unit' => PricingUnit::Kilogram]);
        $shelf = StockItem::factory()->unit(PricingUnit::Kilogram)->create();
        $variant = ProductVariant::factory()->for($product)->create(['stock_item_id' => $shelf->id]);
        $order = Order::factory()->status(OrderStatus::Printing)->create();
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'pricing_unit' => PricingUnit::Kilogram,
        ]);
        $headers = $this->foreman();

        // Act
        $ready = $this->transition($this->show($headers, $order), OrderStatus::Ready);

        // Assert — not offered, and not accepted either: a key the move never described is
        // refused by the same list that describes it.
        $this->assertNull($this->fieldNamed($ready['fields'], 'weight_kg'));

        $refused = $this->move($headers, $order, OrderStatus::Ready, [
            'fields' => ['weight_kg' => 12.5],
        ]);

        $refused->assertStatus(422);
    }

    // ────────────────────── what «نواقص» asks for, line by line ──────────────────────

    public function test_a_shortage_is_asked_for_line_by_line(): void
    {
        // Arrange — two sizes on one order, priced differently. «نواقص» is offered off «جديدة»,
        // which is the only status it is reachable from.
        $order = Order::factory()->create();
        $pieces = OrderItem::factory()->for($order)->create([
            'variant_label' => '30*30',
            'pricing_unit' => PricingUnit::Piece,
            'quantity' => '100',
        ]);
        $kilos = OrderItem::factory()->for($order)->create([
            'variant_label' => 'سادة',
            'pricing_unit' => PricingUnit::Kilogram,
            'quantity' => '50',
        ]);
        $headers = $this->foreman();

        // Act
        $shortage = $this->transition($this->show($headers, $order), OrderStatus::Shortage);
        $keys = array_column($shortage['fields'], 'key');

        // Assert — «كم ناقص» is meaningless for an order; it is a question about a size. Each
        // line asks in its own unit, and the app draws them without knowing what a line is.
        $this->assertSame(["shortage_{$pieces->id}", "shortage_{$kilos->id}", 'reason'], $keys);
        $this->assertStringContainsString('30*30', $shortage['fields'][0]['label']);
        $this->assertStringContainsString('قطعة', $shortage['fields'][0]['label']);
        $this->assertStringContainsString('كجم', $shortage['fields'][1]['label']);
    }

    public function test_a_line_cannot_be_shorter_than_it_was_ordered(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['quantity' => '100']);
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Shortage, [
            'fields' => ["shortage_{$item->id}" => 150],
        ]);

        // Assert — «ناقص ١٥٠ من ١٠٠» is not a shortage, it is a typo.
        $response->assertStatus(422)->assertJsonValidationErrors("fields.shortage_{$item->id}");
    }

    public function test_a_shortage_with_nothing_missing_is_refused(): void
    {
        // Arrange
        $order = Order::factory()->create();
        OrderItem::factory()->for($order)->create(['quantity' => '100']);
        $headers = $this->foreman();

        // Act — the status chosen, and every line left alone.
        $response = $this->move($headers, $order, OrderStatus::Shortage);

        // Assert — a «نواقص» that does not say what is missing is a status nobody can act on.
        $response->assertStatus(422);
        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    public function test_what_is_missing_is_recorded_against_the_size_it_is_missing_from(): void
    {
        // Arrange
        $order = Order::factory()->create();
        $short = OrderItem::factory()->for($order)->create([
            'variant_label' => '30*30',
            'quantity' => '100',
        ]);
        $whole = OrderItem::factory()->for($order)->create([
            'variant_label' => '45*50',
            'quantity' => '80',
        ]);
        $headers = $this->foreman();

        // Act
        $response = $this->move($headers, $order, OrderStatus::Shortage, [
            'fields' => ["shortage_{$short->id}" => 40],
        ]);

        // Assert — the number sits on the line it belongs to, so «ما الناقص ومن أي مقاس» is
        // answered by the order itself rather than by reading a sentence somebody typed.
        $response->assertOk()->assertJsonPath('data.status', 'shortage');
        $this->assertSame('40.000', $short->fresh()->shortage_quantity);
        $this->assertNull($whole->fresh()->shortage_quantity);
    }
}
