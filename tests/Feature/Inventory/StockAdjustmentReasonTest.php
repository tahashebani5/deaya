<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Enums\AdjustmentDirection;
use App\Domain\Inventory\Enums\StockAdjustmentReason;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\Warehouse;
use App\Domain\Inventory\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * «هالك» and «عجز» get a vocabulary.
 *
 * Until now the whole of it was free text in `stock_movements.notes`: a decreasing adjustment
 * said the shelf held less than the book and nothing said whether that was breakage, theft or a
 * miscount. Nobody could answer «كم هالك هذا الشهر؟» without reading every note.
 *
 * **A reason, not a new `MovementType`.** A new case needs an arm in `label()`,
 * `requiresSource()`, `requiresDestination()`, `isDirectional()` *and*
 * `RecordStockMovement::batchSource()`, and `StockLedgerTest` sums a balance by which end of the
 * row is filled — so a mis-shaped new type inverts the ledger invariant silently. A reason
 * cannot: it rides along on a movement whose shape is already right.
 *
 * Arrange - Act - Assert throughout.
 */
class StockAdjustmentReasonTest extends TestCase
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
    private function storekeeper(): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            PermissionName::ViewInventory->value,
            PermissionName::ManageInventory->value,
            PermissionName::ViewStockCost->value,
        ]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * A shelf holding [$onHand] at a known cost.
     *
     * @return array{0: StockItem, 1: Warehouse}
     */
    private function shelf(string $onHand = '1000'): array
    {
        $item = StockItem::factory()->unit(PricingUnit::Piece)->create();
        $warehouse = Warehouse::factory()->create();

        WarehouseStock::factory()->quantity($onHand)->unit($item->unit)->create([
            'warehouse_id' => $warehouse->getKey(),
            'stock_item_id' => $item->getKey(),
        ]);

        StockBatch::factory()->create([
            'warehouse_id' => $warehouse->getKey(),
            'stock_item_id' => $item->getKey(),
            'quantity_received' => $onHand,
            'quantity_remaining' => $onHand,
            'unit_cost' => '2.000',
            'unit' => $item->unit,
        ]);

        return [$item, $warehouse];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function adjust(array $headers, StockItem $item, Warehouse $warehouse, array $overrides = []): TestResponse
    {
        return $this->withHeaders($headers)->postJson('/api/v1/stock-movements/adjustments', array_merge([
            'stock_item_id' => $item->getKey(),
            'warehouse_id' => $warehouse->getKey(),
            'direction' => AdjustmentDirection::Decrease->value,
            'quantity' => '50',
            'notes' => 'طبلية ابتلّت في المخزن',
        ], $overrides));
    }

    // ─────────────────────────── the vocabulary ───────────────────────────

    public function test_a_decrease_must_say_which_kind_of_loss_it_is(): void
    {
        // Arrange
        [$item, $warehouse] = $this->shelf();

        // Act — the payload that was complete yesterday.
        $response = $this->adjust($this->storekeeper(), $item, $warehouse);

        // Assert — «نقص» on its own is the question, not the answer.
        $response->assertStatus(422)->assertJsonValidationErrors('adjustment_reason');
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_the_reason_is_stored_on_the_movement_and_read_back(): void
    {
        // Arrange
        [$item, $warehouse] = $this->shelf();

        // Act
        $response = $this->adjust($this->storekeeper(), $item, $warehouse, [
            'adjustment_reason' => StockAdjustmentReason::Damage->value,
        ]);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.adjustment_reason', 'damage')
            ->assertJsonPath('data.adjustment_reason_label', 'هالك');

        $this->assertSame(
            StockAdjustmentReason::Damage,
            StockMovement::query()->sole()->adjustment_reason,
        );
    }

    public function test_a_shortage_and_a_count_correction_are_two_different_answers(): void
    {
        // Arrange
        [$item, $warehouse] = $this->shelf();
        $headers = $this->storekeeper();

        // Act
        $this->adjust($headers, $item, $warehouse, [
            'adjustment_reason' => StockAdjustmentReason::Shortage->value,
        ])->assertCreated();

        $this->adjust($headers, $item, $warehouse, [
            'adjustment_reason' => StockAdjustmentReason::CountCorrection->value,
        ])->assertCreated();

        // Assert — kept apart on the row, so «عجز» and «فرق جرد» are two lines on a report
        // rather than one bucket somebody has to explain.
        $this->assertSame(
            ['shortage', 'count_correction'],
            StockMovement::query()->orderBy('id')->get()
                ->map(fn (StockMovement $m) => $m->adjustment_reason?->value)->all(),
        );
    }

    public function test_an_increase_has_no_loss_to_name(): void
    {
        // Arrange
        [$item, $warehouse] = $this->shelf();

        // Act — found more than the book said, and «هالك» offered anyway.
        $response = $this->adjust($this->storekeeper(), $item, $warehouse, [
            'direction' => AdjustmentDirection::Increase->value,
            'unit_cost' => '2.000',
            'adjustment_reason' => StockAdjustmentReason::Damage->value,
        ]);

        // Assert — a correction that adds stock is not a loss, and letting the word through
        // would put «هالك» on a row that increased the shelf.
        $response->assertStatus(422)->assertJsonValidationErrors('adjustment_reason');
    }

    public function test_the_reason_reserved_for_a_unit_change_is_not_offered_to_a_storekeeper(): void
    {
        // Arrange
        [$item, $warehouse] = $this->shelf();

        // Act — `unit_change` exists so the discards SetStockItemUnit posts can be told apart
        // from a real loss. It is never something a person records.
        $response = $this->adjust($this->storekeeper(), $item, $warehouse, [
            'adjustment_reason' => StockAdjustmentReason::UnitChange->value,
        ]);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('adjustment_reason');
    }

    public function test_the_ledger_can_be_asked_for_this_months_damage(): void
    {
        // Arrange
        [$item, $warehouse] = $this->shelf();
        $headers = $this->storekeeper();
        $this->adjust($headers, $item, $warehouse, [
            'adjustment_reason' => StockAdjustmentReason::Damage->value,
        ])->assertCreated();
        $this->adjust($headers, $item, $warehouse, [
            'adjustment_reason' => StockAdjustmentReason::Shortage->value,
        ])->assertCreated();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/stock-movements?adjustment_reason=damage');

        // Assert — the question the free-text note could never answer.
        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('damage', $response->json('data.0.adjustment_reason'));
    }
}
