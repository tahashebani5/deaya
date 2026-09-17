<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockBatchConsumption;
use App\Domain\Inventory\Models\StockBatchRevaluation;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Investor\Models\InvestorDeal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Correcting what a quantity of stock is carried at.
 *
 * **The one write in this domain that moves money without moving stock**, so the first thing
 * every test here asserts is that the balance did not move — and that
 * `SUM(quantity_remaining)` still equals it, which is the invariant `StockBatchLedgerTest`
 * exists for and this feature is most likely to break.
 *
 * State is built through the real API for the same reason that file gives: a factory writing a
 * batch would assume exactly what is under test.
 *
 * Arrange - Act - Assert throughout.
 */
class StockBatchRevaluationTest extends TestCase
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

    /**
     * @return array<string, string>
     */
    private function storekeeper(): array
    {
        return $this->auth(PermissionName::ViewInventory, PermissionName::ManageInventory);
    }

    /**
     * @return array<string, string>
     */
    private function accountant(): array
    {
        return $this->auth(
            PermissionName::ViewInventory,
            PermissionName::ManageInventory,
            PermissionName::RevalueStock,
        );
    }

    private function balanceOf(Warehouse $warehouse, StockItem $item): string
    {
        return (string) (WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $item->id)
            ->first()?->quantity ?? '0.000');
    }

    private function assertBatchesReconcile(Warehouse $warehouse, StockItem $item): void
    {
        $batches = (string) number_format((float) StockBatch::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stock_item_id', $item->id)
            ->sum('quantity_remaining'), 3, '.', '');

        $this->assertSame(
            $this->balanceOf($warehouse, $item),
            $batches,
            'The sum of remaining batch quantity no longer equals the warehouse balance.',
        );
    }

    /**
     * A shelf holding one layer, priced as asked — the shape almost every test here starts from.
     *
     * **Posted as the caller's own user, never as a second one.** One test process authenticates
     * once: a request made under a different token after the first would still run as whoever
     * signed in first, and the permission tests below would pass for the wrong reason.
     *
     * @param  array<string, string>  $headers
     * @return array{0: Warehouse, 1: StockItem, 2: StockBatch}
     */
    private function shelfWith(array $headers, string $quantity, string $unitCost = '0'): array
    {
        $warehouse = Warehouse::factory()->create();
        $item = StockItem::factory()->create();

        $this->withHeaders($headers)
            ->postJson('/api/v1/stock-movements/arrivals', [
                'stock_item_id' => $item->id,
                'to_warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ])->assertCreated();

        return [$warehouse, $item, StockBatch::query()->sole()];
    }

    // ───────────────────────────── the whole layer ─────────────────────────────

    public function test_a_layer_can_be_repriced_without_moving_the_balance(): void
    {
        // Arrange — the shape the opening-balance backfill left everywhere: stock on a shelf
        // carried at nothing. One actor throughout: see shelfWith().
        $headers = $this->accountant();
        [$warehouse, $item, $batch] = $this->shelfWith($headers, '500');

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '3.5',
                'reason' => 'رصيد افتتاحي سُعِّر من آخر فاتورة',
            ]);

        // Assert — the cost moved and nothing else did.
        $response->assertOk()
            ->assertJsonPath('data.unit_cost', '3.500')
            ->assertJsonPath('data.quantity_remaining', '500.000');

        $this->assertSame('500.000', $this->balanceOf($warehouse, $item));
        $this->assertBatchesReconcile($warehouse, $item);
        $this->assertSame(1, StockBatch::query()->count(), 'A whole-layer revaluation must not split.');
        $this->assertNotNull($batch->fresh()->revalued_at);
    }

    public function test_the_new_cost_is_what_the_next_order_pays(): void
    {
        // Arrange
        $headers = $this->accountant();
        [$warehouse, $item, $batch] = $this->shelfWith($headers, '500');

        $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '3.5',
                'reason' => 'فاتورة المورد',
            ])->assertOk();

        // Act — stock drawn after the correction.
        $this->withHeaders($headers)
            ->postJson('/api/v1/stock-movements/fulfillments', [
                'stock_item_id' => $item->id,
                'from_warehouse_id' => $warehouse->id,
                'quantity' => 100,
            ])->assertCreated();

        // Assert — 100 × 3.5. Before this feature it would have been nothing at all.
        $cost = StockBatchConsumption::query()->sum('total_cost');
        $this->assertSame('350.00', number_format((float) $cost, 2, '.', ''));
        $this->assertBatchesReconcile($warehouse, $item);
    }

    public function test_stock_already_drawn_keeps_the_cost_it_left_at(): void
    {
        // Arrange — 200 out at the old price, then the price is corrected.
        $headers = $this->accountant();
        [$warehouse, $item, $batch] = $this->shelfWith($headers, '500', '1');

        $this->withHeaders($headers)
            ->postJson('/api/v1/stock-movements/fulfillments', [
                'stock_item_id' => $item->id,
                'from_warehouse_id' => $warehouse->id,
                'quantity' => 200,
            ])->assertCreated();

        // Act
        $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '9',
                'reason' => 'السعر كان خطأ',
            ])->assertOk();

        // Assert — **prospective, never retrospective.** The 200 that left cost 200, and the
        // order that took them keeps that figure; rewriting it would restate a closed sale.
        $this->assertSame('200.00', number_format((float) StockBatchConsumption::query()->sum('total_cost'), 2, '.', ''));
        $this->assertBatchesReconcile($warehouse, $item);
    }

    // ───────────────────────────── the split ─────────────────────────────

    public function test_repricing_part_of_a_layer_splits_it_and_keeps_the_balance(): void
    {
        // Arrange
        $headers = $this->accountant();
        [$warehouse, $item, $batch] = $this->shelfWith($headers, '500');

        // Act — only 100 of the 500 is being corrected.
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '3.5',
                'quantity' => '100',
                'reason' => 'جزء من الشحنة بسعر مختلف',
            ]);

        // Assert — the original keeps the new cost and 100 of the stock; the untouched 400
        // moves to a row of its own at the price it already had.
        $response->assertOk()
            ->assertJsonPath('data.id', $batch->id)
            ->assertJsonPath('data.unit_cost', '3.500')
            ->assertJsonPath('data.quantity_remaining', '100.000');

        $child = StockBatch::query()->where('split_from_batch_id', $batch->id)->sole();
        $this->assertSame('400.000', (string) $child->quantity_remaining);
        $this->assertSame('400.000', (string) $child->quantity_received);
        $this->assertSame('0.000', (string) $child->unit_cost);

        // The shelf did not move, and the two layers still add up to it.
        $this->assertSame('500.000', $this->balanceOf($warehouse, $item));
        $this->assertBatchesReconcile($warehouse, $item);
    }

    public function test_the_repriced_quantity_is_what_the_next_order_takes_first(): void
    {
        // Arrange — the whole point of leaving the new cost on the original row.
        $headers = $this->accountant();
        [$warehouse, $item, $batch] = $this->shelfWith($headers, '500');

        $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '3.5',
                'quantity' => '100',
                'reason' => 'جزء من الشحنة',
            ])->assertOk();

        // Act
        $this->withHeaders($headers)
            ->postJson('/api/v1/stock-movements/fulfillments', [
                'stock_item_id' => $item->id,
                'from_warehouse_id' => $warehouse->id,
                'quantity' => 100,
            ])->assertCreated();

        // Assert — 100 × 3.5, not 100 × 0: FIFO ties break on id, so the original row goes
        // first, and it is the one carrying the corrected price.
        $this->assertSame('350.00', number_format((float) StockBatchConsumption::query()->sum('total_cost'), 2, '.', ''));
        $this->assertSame('0.000', (string) $batch->fresh()->quantity_remaining);
        $this->assertBatchesReconcile($warehouse, $item);
    }

    public function test_a_split_keeps_the_age_and_the_origin_of_the_stock(): void
    {
        // Arrange
        $headers = $this->accountant();
        [, , $batch] = $this->shelfWith($headers, '500');
        $movementId = StockMovement::query()->sole()->id;

        // Act
        $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '3.5',
                'quantity' => '100',
                'reason' => 'جزء من الشحنة',
            ])->assertOk();

        // Assert — stock does not get younger, or change where it came from, by being split.
        $child = StockBatch::query()->where('split_from_batch_id', $batch->id)->sole();
        $this->assertEquals($batch->received_at, $child->received_at);
        $this->assertSame($batch->source_type, $child->source_type);
        $this->assertSame($movementId, $child->stock_movement_id);
    }

    public function test_a_split_leaves_each_row_honest_about_what_was_drawn_from_it(): void
    {
        // Arrange — 200 already gone before anybody corrects anything.
        $headers = $this->accountant();
        [$warehouse, $item, $batch] = $this->shelfWith($headers, '500', '1');

        $this->withHeaders($headers)
            ->postJson('/api/v1/stock-movements/fulfillments', [
                'stock_item_id' => $item->id,
                'from_warehouse_id' => $warehouse->id,
                'quantity' => 200,
            ])->assertCreated();

        // Act — reprice 100 of the 300 that remain.
        $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '9',
                'quantity' => '100',
                'reason' => 'تصحيح جزئي',
            ])->assertOk();

        // Assert — the drawn quantity stays with the row that holds its consumption rows, so
        // `received − remaining` still reads as what left each layer.
        $parent = $batch->fresh();
        $child = StockBatch::query()->where('split_from_batch_id', $batch->id)->sole();

        $this->assertSame('300.000', (string) $parent->quantity_received);
        $this->assertSame('100.000', (string) $parent->quantity_remaining);
        $this->assertSame('200.000', $parent->consumedQuantity());

        $this->assertSame('200.000', (string) $child->quantity_received);
        $this->assertSame('200.000', (string) $child->quantity_remaining);
        $this->assertSame('0.000', $child->consumedQuantity());

        $this->assertBatchesReconcile($warehouse, $item);
    }

    // ───────────────────────────── the gate ─────────────────────────────

    public function test_a_fully_consumed_layer_cannot_be_repriced(): void
    {
        // Arrange
        $headers = $this->accountant();
        [$warehouse, $item, $batch] = $this->shelfWith($headers, '100', '1');

        $this->withHeaders($headers)
            ->postJson('/api/v1/stock-movements/fulfillments', [
                'stock_item_id' => $item->id,
                'from_warehouse_id' => $warehouse->id,
                'quantity' => 100,
            ])->assertCreated();

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '9',
                'reason' => 'متأخر جداً',
            ]);

        // Assert — nothing is left to correct, and pretending otherwise would look like it
        // fixed the order this stock went into. It cannot.
        $response->assertStatus(422)->assertJsonValidationErrors('unit_cost');
        $this->assertSame('1.000', (string) $batch->fresh()->unit_cost);
    }

    public function test_more_than_the_layer_holds_is_refused(): void
    {
        // Arrange
        $headers = $this->accountant();
        [, , $batch] = $this->shelfWith($headers, '100');

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '3.5',
                'quantity' => '500',
                'reason' => 'أكثر مما فيها',
            ]);

        // Assert — checked under the lock, not at validation: the remainder moves.
        $response->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->assertSame(1, StockBatch::query()->count());
    }

    public function test_a_reason_is_required(): void
    {
        // Arrange
        $headers = $this->accountant();
        [, , $batch] = $this->shelfWith($headers, '100');

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", ['unit_cost' => '3.5']);

        // Assert — a change to the books with no physical event behind it must not be able to
        // exist unexplained.
        $response->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_repricing_needs_its_own_grant(): void
    {
        // Arrange — a storekeeper who may record every movement there is. The layer is built by
        // the factory rather than posted, so this test authenticates exactly once: a second
        // token in one test process would still run as whoever signed in first, and the refusal
        // under test would never be reached.
        $batch = StockBatch::factory()->unitCost('0.000')->create();

        // Act
        $response = $this->withHeaders($this->storekeeper())
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '3.5',
                'reason' => 'محاولة',
            ]);

        // Assert — moving stock and revaluing it are different trust levels.
        $response->assertForbidden();
        $this->assertSame('0.000', (string) $batch->fresh()->unit_cost);
    }

    // ───────────────────────────── the record ─────────────────────────────

    public function test_the_revaluation_is_recorded_with_both_costs_and_its_reason(): void
    {
        // Arrange
        $headers = $this->accountant();
        [, , $batch] = $this->shelfWith($headers, '500', '1');

        // Act
        $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '3.5',
                'quantity' => '100',
                'reason' => 'فاتورة المورد وصلت بسعر مختلف',
            ])->assertOk();

        // Assert — the event, not the row: «من كم إلى كم، وكم، ولماذا».
        $entry = StockBatchRevaluation::query()->sole();
        $this->assertSame($batch->id, $entry->stock_batch_id);
        $this->assertSame('100.000', (string) $entry->quantity);
        $this->assertSame('1.000', (string) $entry->old_unit_cost);
        $this->assertSame('3.500', (string) $entry->new_unit_cost);
        $this->assertSame('فاتورة المورد وصلت بسعر مختلف', $entry->reason);
        $this->assertNotNull($entry->user_id);
    }

    // ───────────────────────────── reading them ─────────────────────────────

    public function test_the_uncosted_list_is_the_work_queue(): void
    {
        // Arrange — one layer nobody priced and one that was.
        $headers = $this->accountant();
        [$warehouse, $item] = $this->shelfWith($headers, '100');

        $this->withHeaders($headers)
            ->postJson('/api/v1/stock-movements/arrivals', [
                'stock_item_id' => $item->id,
                'to_warehouse_id' => $warehouse->id,
                'quantity' => 50,
                'unit_cost' => 7,
            ])->assertCreated();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson('/api/v1/stock-batches?uncosted=1');

        // Assert — only the one carrying no price, and it says so.
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.unit_cost', '0.000')
            ->assertJsonPath('data.0.is_uncosted', true)
            ->assertJsonPath('data.0.can_be_revalued', true)
            ->assertJsonPath('data.0.is_partly_consumed', false);
    }

    public function test_a_layer_says_whether_correcting_it_will_only_reach_part_of_it(): void
    {
        // Arrange
        $headers = $this->accountant();
        [$warehouse, $item] = $this->shelfWith($headers, '500', '1');

        $this->withHeaders($headers)
            ->postJson('/api/v1/stock-movements/fulfillments', [
                'stock_item_id' => $item->id,
                'from_warehouse_id' => $warehouse->id,
                'quantity' => 200,
            ])->assertCreated();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/stock-batches?warehouse_id={$warehouse->id}");

        // Assert — the app draws the warning from this, rather than working it out itself.
        $response->assertOk()
            ->assertJsonPath('data.0.is_partly_consumed', true)
            ->assertJsonPath('data.0.quantity_consumed', '200.000')
            ->assertJsonPath('data.0.can_be_revalued', true);
    }

    public function test_a_used_up_layer_is_out_of_the_list_unless_it_is_asked_for(): void
    {
        // Arrange
        $headers = $this->accountant();
        [$warehouse, $item] = $this->shelfWith($headers, '100', '1');

        $this->withHeaders($headers)
            ->postJson('/api/v1/stock-movements/fulfillments', [
                'stock_item_id' => $item->id,
                'from_warehouse_id' => $warehouse->id,
                'quantity' => 100,
            ])->assertCreated();

        // Act & Assert — a layer with nothing left is not part of any queue.
        $this->withHeaders($headers)
            ->getJson("/api/v1/stock-batches?warehouse_id={$warehouse->id}")
            ->assertOk()->assertJsonCount(0, 'data');

        $this->withHeaders($headers)
            ->getJson("/api/v1/stock-batches?warehouse_id={$warehouse->id}&remaining=0")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can_be_revalued', false);
    }

    public function test_a_layer_an_investor_funded_is_not_offered_for_repricing(): void
    {
        // Arrange — a deal's own stock, sitting on a shelf like any other layer. Built by the
        // factory: what is under test is the flag the app reads, not how the deal took delivery.
        $deal = InvestorDeal::factory()->open()->create();
        $batch = StockBatch::factory()->unitCost('4.000')->create(['investor_deal_id' => $deal->id]);
        $headers = $this->accountant();

        // Act
        $list = $this->withHeaders($headers)->getJson('/api/v1/stock-batches');
        $refusal = $this->withHeaders($headers)
            ->patchJson("/api/v1/stock-batches/{$batch->id}/cost", [
                'unit_cost' => '9',
                'reason' => 'فاتورة المورد وصلت بسعر مختلف',
            ]);

        // Assert — **the flag has to agree with the refusal.** A layer the server will not
        // reprice must not come back saying it can be, or the app draws a button whose only
        // outcome is a 422 in Arabic.
        $list->assertOk()->assertJsonPath('data.0.can_be_revalued', false);
        $refusal->assertStatus(422);
        $this->assertSame('4.000', (string) $batch->fresh()->unit_cost);
    }

    public function test_reading_the_layers_needs_only_the_viewing_grant(): void
    {
        // Arrange — seeing what stock cost is not the same permission as changing it.
        StockBatch::factory()->create();

        // Act & Assert
        $this->withHeaders($this->auth(PermissionName::ViewInventory))
            ->getJson('/api/v1/stock-batches')
            ->assertOk();
    }

    public function test_reading_the_layers_is_refused_without_any_grant(): void
    {
        // Arrange — its own test rather than a second request above: one test process
        // authenticates once, so two tokens in one test would both run as the first.
        StockBatch::factory()->create();

        // Act & Assert
        $this->withHeaders($this->auth())
            ->getJson('/api/v1/stock-batches')
            ->assertForbidden();
    }

    public function test_a_material_offers_the_last_price_anybody_recorded_for_it(): void
    {
        // Arrange — two arrivals, the second cheaper, so "last" cannot be confused with
        // "highest" or "first".
        $headers = $this->accountant();
        [$warehouse, $item] = $this->shelfWith($headers, '100', '7');

        $this->withHeaders($headers)
            ->postJson('/api/v1/stock-movements/arrivals', [
                'stock_item_id' => $item->id,
                'to_warehouse_id' => $warehouse->id,
                'quantity' => 50,
                'unit_cost' => 4,
            ])->assertCreated();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/stock-items/{$item->id}");

        // Assert — dated and sourced, because a six-month-old price should look it. Offered to
        // the form, never applied by the server: see the note on `latestCostedBatch()`.
        $response->assertOk()
            ->assertJsonPath('data.last_known_unit_cost.unit_cost', '4.000')
            ->assertJsonPath('data.last_known_unit_cost.source_type_label', 'توريد');
        $this->assertNotNull($response->json('data.last_known_unit_cost.received_at'));
    }

    public function test_a_material_nobody_ever_priced_offers_nothing(): void
    {
        // Arrange — a zero-cost layer is not a price; it is the absence of one.
        $headers = $this->accountant();
        [, $item] = $this->shelfWith($headers, '100');

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/stock-items/{$item->id}");

        // Assert — nothing to suggest, which is exactly when the box must stay empty.
        $response->assertOk()->assertJsonPath('data.last_known_unit_cost', null);
    }

    public function test_a_layer_names_the_movement_that_opened_it(): void
    {
        // Arrange — the traceability this table went without until now.
        $headers = $this->accountant();
        [$warehouse] = $this->shelfWith($headers, '100', '2');
        $movement = StockMovement::query()->sole();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/stock-batches?warehouse_id={$warehouse->id}");

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.stock_movement_id', $movement->id)
            ->assertJsonPath('data.0.recorded_by', $movement->employee_id)
            // Nothing points at a purchase order: this arrived by hand, not against one.
            ->assertJsonPath('data.0.purchase_order_id', null);
    }
}
