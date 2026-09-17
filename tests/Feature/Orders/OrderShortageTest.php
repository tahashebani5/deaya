<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Order\Actions\RecalculateOrderTotals;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What is missing, and what it costs.
 *
 * **One rule, and everything here is a consequence of it:**
 *
 *     الكمية المحتسبة = المطلوبة − الناقص المسجَّل الآن
 *     سطر البند       = سعر الوحدة المتفق عليه × الكمية المحتسبة
 *
 * The ordered quantity never moves — it is what the customer asked for, and an order that
 * rewrote it would lose the question the shortage is an answer to. Only `shortage_quantity`
 * travels, which is what makes the money **reversible without a reversing entry**: put the
 * shortage back to zero and the invoice is the number it was, to the fils, because it was never
 * a written balance in the first place.
 *
 * Arrange - Act - Assert throughout.
 */
class OrderShortageTest extends TestCase
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
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** Somebody who may record a shortage and move an order off one. */
    private function storeman(): array
    {
        return $this->auth(
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToShortage,
            PermissionName::MoveOrderToReadyToPrint,
            PermissionName::MoveOrderToDesigning,
            PermissionName::MoveOrderToPrinting,
        );
    }

    /**
     * A shelf holding far more than the order will draw.
     *
     * Leaving «نواقص» now lands on «جاهزة للطباعة», and that is where the stock goes out — so
     * these tests need a warehouse that can cover the run. It is deliberately generous: what is
     * under test here is the *invoice* following the shortage, and a run refused for a balance
     * would be a different test failing in this one's name.
     */
    private function stockedWarehouse(OrderItem $item): Warehouse
    {
        $warehouse = Warehouse::factory()->create();

        WarehouseStock::factory()->quantity('1000')->create([
            'warehouse_id' => $warehouse->id,
            'stock_item_id' => ProductVariant::query()
                ->whereKey($item->product_variant_id)
                ->value('stock_item_id'),
        ]);

        return $warehouse;
    }

    /**
     * One line of 300 at 1.550 — the tier the *ordered* quantity earned — on an order whose
     * totals add up before anything goes missing.
     *
     * @return array{0: Order, 1: OrderItem}
     */
    private function orderOf300(OrderStatus $status = OrderStatus::New): array
    {
        $order = Order::factory()->status($status)->create(['delivery_price' => '20.00']);

        $item = OrderItem::factory()->for($order)->create([
            'variant_label' => '25*35',
            'quantity' => '300.000',
            'unit_price' => '1.550',
            'line_total' => '465.00',
        ]);

        app(RecalculateOrderTotals::class)($order->refresh());

        return [$order->refresh(), $item];
    }

    private function setShortages(array $headers, Order $order, array $shortages): TestResponse
    {
        return $this->withHeaders($headers)->patchJson(
            "/api/v1/orders/{$order->id}/shortages",
            ['shortages' => $shortages],
        );
    }

    // ───────────────────────── the invoice follows the shortage ─────────────────────────

    public function test_a_line_is_billed_for_what_is_left_of_it(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $headers = $this->storeman();

        // Act — a hundred of the three hundred are not there.
        $response = $this->setShortages($headers, $order, [$item->id => '100']);

        // Assert — 200 × 1.550, and the order's own totals follow the line rather than being
        // written separately. The 20.00 of delivery is beside the total, never inside it.
        $response->assertOk();
        $this->assertSame('310.00', (string) $item->fresh()->line_total);
        $this->assertSame('310.00', (string) $order->fresh()->items_total);
        $this->assertSame('310.00', (string) $order->fresh()->grand_total);
    }

    public function test_the_ordered_quantity_is_never_rewritten(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $headers = $this->storeman();

        // Act
        $this->setShortages($headers, $order, [$item->id => '100'])->assertOk();

        // Assert — «طلب ٣٠٠ ووصله ٢٠٠» is two facts, and an order that overwrote the first with
        // the second would have no answer to what went wrong.
        $this->assertSame('300.000', (string) $item->fresh()->quantity);
        $this->assertSame('100.000', (string) $item->fresh()->shortage_quantity);
    }

    public function test_the_agreed_unit_price_survives_the_shortage(): void
    {
        // Arrange — 300 earned the 1.550 tier. 200 on its own would not have.
        [$order, $item] = $this->orderOf300();
        $headers = $this->storeman();

        // Act
        $this->setShortages($headers, $order, [$item->id => '100'])->assertOk();

        // Assert — the shortage is ours, not the customer's, and re-quoting the smaller quantity
        // would charge them *more* per bag because we failed to deliver.
        $this->assertSame('1.550', (string) $item->fresh()->unit_price);
    }

    public function test_putting_the_shortage_back_restores_the_invoice_exactly(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $headers = $this->storeman();
        $this->setShortages($headers, $order, [$item->id => '100'])->assertOk();

        // Act — the stock arrived after all.
        $this->setShortages($headers, $order, [$item->id => null])->assertOk();

        // Assert — to the fils, and with no reversing entry anywhere: the invoice is derived
        // from the shortage rather than adjusted by it.
        $this->assertNull($item->fresh()->shortage_quantity);
        $this->assertSame('465.00', (string) $item->fresh()->line_total);
        $this->assertSame('465.00', (string) $order->fresh()->items_total);
        $this->assertSame('465.00', (string) $order->fresh()->grand_total);
    }

    public function test_a_line_that_goes_entirely_missing_costs_nothing(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $headers = $this->storeman();

        // Act
        $this->setShortages($headers, $order, [$item->id => '300'])->assertOk();

        // Assert — nothing of it was delivered, so nothing of it is charged. The line stays on
        // the order saying what was asked for.
        $this->assertSame('0.00', (string) $item->fresh()->line_total);
        $this->assertSame('0.00', (string) $order->fresh()->items_total);
    }

    public function test_a_line_cannot_be_shorter_than_it_was_ordered(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $headers = $this->storeman();

        // Act
        $response = $this->setShortages($headers, $order, [$item->id => '400']);

        // Assert — «ناقص ٤٠٠ من ٣٠٠» is not a shortage, it is a typo, and a negative line total
        // is what it would have produced.
        $response->assertStatus(422)->assertJsonValidationErrors("shortages.{$item->id}");
        $this->assertSame('465.00', (string) $item->fresh()->line_total);
    }

    public function test_a_line_from_another_order_is_refused(): void
    {
        // Arrange
        [$order] = $this->orderOf300();
        [, $stranger] = $this->orderOf300();
        $headers = $this->storeman();

        // Act
        $response = $this->setShortages($headers, $order, [$stranger->id => '10']);

        // Assert — the ids are the customer's to type, so the check is the server's to make.
        $response->assertStatus(422);
        $this->assertNull($stranger->fresh()->shortage_quantity);
    }

    // ─────────────────────────────── money already taken ───────────────────────────────

    public function test_an_order_paid_in_full_before_the_shortage_reads_as_overpaid(): void
    {
        // Arrange — 465 collected up front, then a hundred bags fail to turn up. One clerk doing
        // both, which is how it happens: the counter takes the money, the store finds the gap.
        [$order, $item] = $this->orderOf300();
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToShortage,
            PermissionName::ViewOrderPayments,
            PermissionName::RecordOrderPayments,
        );

        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/payments", [
            'amount' => '465.00',
            'method' => PaymentMethod::Cash->value,
        ])->assertCreated();

        // Act
        $this->setShortages($headers, $order, [$item->id => '100'])->assertOk();

        // Assert — no new machinery: the invoice dropped under what was taken, and «المتبقي»
        // going negative is what the screen already draws as «زائد». Refunding it is the
        // existing ledger path.
        $this->assertSame('-155.00', $order->fresh()->remainingAmount());
    }

    // ──────────────────────────── leaving «نواقص» ────────────────────────────

    public function test_leaving_a_shortage_asks_what_arrived_of_it(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300(OrderStatus::Shortage);
        $item->forceFill(['shortage_quantity' => '100'])->save();
        app(RecalculateOrderTotals::class)($order->refresh());
        $headers = $this->storeman();

        // Act
        $transitions = $this->withHeaders($headers)
            ->getJson("/api/v1/orders/{$order->id}")
            ->json('data.available_transitions');

        $handover = collect($transitions)->firstWhere('status', OrderStatus::ReadyToPrint->value);

        // Assert — one field per short line, bounded by what is missing from it and filled in
        // with the whole of it, because «وصل الكل» is what leaving this status usually means.
        $field = collect($handover['fields'])->firstWhere('key', "received_{$item->id}");

        $this->assertNotNull($field);
        $this->assertSame('100', $field['value']);
        $this->assertEquals(100, $field['max']);
    }

    public function test_the_whole_shortage_arriving_puts_the_invoice_back(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300(OrderStatus::Shortage);
        $item->forceFill(['shortage_quantity' => '100'])->save();
        app(RecalculateOrderTotals::class)($order->refresh());
        $headers = $this->storeman();

        $warehouse = $this->stockedWarehouse($item);

        // Act
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => [
                "received_{$item->id}" => '100',
                'warehouse_id' => $warehouse->id,
            ],
        ]);

        // Assert — the move and the money in one transaction. The stock all arrived, so the
        // order is complete and may be handed to the press.
        $response->assertOk()->assertJsonPath('data.status', 'ready_to_print');
        $this->assertNull($item->fresh()->shortage_quantity);
        $this->assertSame('465.00', (string) $order->fresh()->items_total);
    }

    public function test_a_part_of_the_shortage_arriving_leaves_the_rest_short(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300(OrderStatus::Shortage);
        $headers = $this->storeman();

        // Recorded through the endpoint that owns the column, so `line_total` and the order's
        // totals are re-derived with it. The arrange has to leave the order in a state the domain
        // could actually have produced, because the refusal below rolls the move back and this is
        // what is left standing afterwards.
        $this->setShortages($headers, $order, [$item->id => '100'])->assertOk();

        $warehouse = $this->stockedWarehouse($item);

        // Act — sixty of the hundred came in, so forty are still missing.
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => [
                "received_{$item->id}" => '60',
                'warehouse_id' => $warehouse->id,
            ],
        ]);

        // Assert — **refused, and nothing of it kept.** «جاهزة للطباعة» promises the press a
        // complete order, and forty bags are still missing; the whole move is one transaction, so
        // the partial arrival it carried goes back with it rather than leaving the order half
        // corrected by a move that failed.
        //
        // That is not a dead end for the storeman: a delivery that only partly arrived is
        // recorded on `PATCH /orders/{order}/shortages` — the screen built for exactly this,
        // pinned below — and the handover is attempted once the rest turns up.
        $response->assertStatus(422);
        $this->assertStringContainsString('ما زال ناقصاً', $response->json('message'));

        $this->assertSame(OrderStatus::Shortage, $order->fresh()->status);
        $this->assertSame('100.000', (string) $item->fresh()->shortage_quantity);
        $this->assertSame('310.00', (string) $order->fresh()->items_total);
    }

    public function test_nothing_arriving_leaves_the_shortage_where_it_was(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300(OrderStatus::Shortage);
        $headers = $this->storeman();

        // Recorded through the endpoint that owns the column, so `line_total` and the order's
        // totals are re-derived with it. The arrange has to leave the order in a state the domain
        // could actually have produced, because the refusal below rolls the move back and this is
        // what is left standing afterwards.
        $this->setShortages($headers, $order, [$item->id => '100'])->assertOk();

        $warehouse = $this->stockedWarehouse($item);

        // Act — zero typed over the prefill: nothing came in.
        $response = $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::ReadyToPrint->value,
            'fields' => [
                "received_{$item->id}" => '0',
                'warehouse_id' => $warehouse->id,
            ],
        ]);

        // Assert — **the job no longer starts on what is here.** It used to: «نواقص» led straight
        // to the press, and an order could be printed short with the invoice cut to match. Now
        // the road out runs through a status that tells another department the order is complete,
        // so an order nothing arrived for cannot take it — and the message names the size that is
        // missing, because the person refused is standing at the shelves.
        $response->assertStatus(422);
        $this->assertStringContainsString('25*35', $response->json('message'));

        $this->assertSame(OrderStatus::Shortage, $order->fresh()->status);
        $this->assertSame('100.000', (string) $item->fresh()->shortage_quantity);
        $this->assertSame('310.00', (string) $order->fresh()->items_total);
    }

    public function test_cancelling_a_shortage_leaves_its_numbers_alone(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300(OrderStatus::Shortage);
        $item->forceFill(['shortage_quantity' => '100'])->save();
        app(RecalculateOrderTotals::class)($order->refresh());
        $headers = $this->auth(
            PermissionName::ViewOrders,
            PermissionName::MoveOrderToShortage,
            PermissionName::CancelOrders,
        );

        // Act
        $this->withHeaders($headers)->postJson("/api/v1/orders/{$order->id}/status", [
            'status' => OrderStatus::Cancelled->value,
            'reason' => 'العميل تراجع',
        ])->assertOk();

        // Assert — a written-off order is not asked what arrived; the record of what was short
        // when it was written off is the point.
        $this->assertSame('100.000', (string) $item->fresh()->shortage_quantity);
    }

    // ──────────────────────────────── who and when ────────────────────────────────

    public function test_the_shortage_closes_when_the_bags_exist(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300(OrderStatus::Ready);
        $headers = $this->storeman();

        // Act
        $response = $this->setShortages($headers, $order, [$item->id => '100']);

        // Assert — «جاهزة» means the run is made and counted, so the number is no longer an
        // estimate somebody corrects. The same line the items themselves are locked at.
        $response->assertStatus(422);
        $this->assertNull($item->fresh()->shortage_quantity);
    }

    public function test_an_order_standing_on_a_shortage_still_takes_a_correction(): void
    {
        // Arrange — the status the order is *in* while the number is being argued about. It was
        // missing from the editable list, which made the one status named after the problem the
        // one status that could not fix it.
        [$order, $item] = $this->orderOf300(OrderStatus::Shortage);
        $item->forceFill(['shortage_quantity' => '100'])->save();
        $headers = $this->storeman();

        // Act
        $response = $this->setShortages($headers, $order, [$item->id => '40']);

        // Assert
        $response->assertOk();
        $this->assertSame('40.000', (string) $item->fresh()->shortage_quantity);
    }

    public function test_recording_a_shortage_costs_its_own_permission(): void
    {
        // Arrange — somebody who may read orders and nothing else.
        [$order, $item] = $this->orderOf300();
        $headers = $this->auth(PermissionName::ViewOrders);

        // Act
        $response = $this->setShortages($headers, $order, [$item->id => '100']);

        // Assert — the same grant that moves an order into «نواقص», because it is the same
        // decision: what is missing, and what the customer is therefore charged.
        $response->assertForbidden();
        $this->assertNull($item->fresh()->shortage_quantity);
    }

    // ─────────────────────────────── what the app reads ───────────────────────────────

    public function test_the_line_says_what_is_actually_being_charged_for(): void
    {
        // Arrange
        [$order, $item] = $this->orderOf300();
        $headers = $this->storeman();
        $this->setShortages($headers, $order, [$item->id => '100'])->assertOk();

        // Act
        $line = $this->withHeaders($headers)
            ->getJson("/api/v1/orders/{$order->id}")
            ->json('data.items.0');

        // Assert — three numbers, because the screen has three things to say: what was ordered,
        // what is missing, and what the line is priced on. Leaving the app to subtract would put
        // the rule in two places.
        $this->assertSame('300.000', $line['quantity']);
        $this->assertSame('100.000', $line['shortage_quantity']);
        $this->assertSame('200.000', $line['billable_quantity']);
        $this->assertSame('310.00', $line['line_total']);
    }
}
