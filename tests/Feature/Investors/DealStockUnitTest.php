<?php

declare(strict_types=1);

namespace Tests\Feature\Investors;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Inventory\Models\StockBatch;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Queries\DealStockPosition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «كيف يكون في فواصل؟» — because the goods are weighed, not counted.
 *
 * A deal's quantities are decimal(12,3) and 174.9 is a perfectly ordinary kilogram figure, but
 * the screen printed the number bare and the owner had no way to tell a kilo from a bag. The
 * position therefore names the unit its own quantities are in, the same `unit`/`unit_label` pair
 * every other quantity in this API carries.
 *
 * It is read off the cost layers rather than off the deal's items, because the layers are what
 * the quantities were summed from — and a deal holding two units has no one word for the total,
 * so it says nothing rather than picking a side.
 *
 * Arrange - Act - Assert throughout.
 */
class DealStockUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_position_names_the_unit_its_quantities_are_weighed_in(): void
    {
        // Arrange: a deal holding two layers of the same weighed shelf.
        $deal = InvestorDeal::factory()->open()->create();
        StockBatch::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'unit' => PricingUnit::Kilogram,
        ]);
        StockBatch::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'unit' => PricingUnit::Kilogram,
        ]);

        // Act
        $position = app(DealStockPosition::class)((int) $deal->getKey());

        // Assert
        $this->assertSame('kilogram', $position['unit']);
        $this->assertSame('كجم', $position['unit_label']);
    }

    public function test_a_deal_holding_two_units_names_neither(): void
    {
        // Arrange: one shelf counted by the piece, one weighed — the total is in no single unit.
        $deal = InvestorDeal::factory()->open()->create();
        StockBatch::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'unit' => PricingUnit::Kilogram,
        ]);
        StockBatch::factory()->create([
            'investor_deal_id' => $deal->getKey(),
            'unit' => PricingUnit::Piece,
        ]);

        // Act
        $position = app(DealStockPosition::class)((int) $deal->getKey());

        // Assert
        $this->assertNull($position['unit']);
        $this->assertNull($position['unit_label']);
    }

    public function test_a_deal_with_no_goods_yet_names_no_unit(): void
    {
        // Arrange
        $deal = InvestorDeal::factory()->open()->create();

        // Act
        $position = app(DealStockPosition::class)((int) $deal->getKey());

        // Assert
        $this->assertNull($position['unit']);
        $this->assertNull($position['unit_label']);
    }
}
