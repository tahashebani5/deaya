<?php

namespace Database\Factories;

use App\Domain\Inventory\Models\StockItem;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestorDealItem>
 */
class InvestorDealItemFactory extends Factory
{
    /** @var class-string<InvestorDealItem> */
    protected $model = InvestorDealItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'investor_deal_id' => InvestorDeal::factory(),
            'stock_item_id' => StockItem::factory(),
            'quantity_expected' => '10000.000',
            'expected_unit_cost' => '2.000',
            'expected_unit_price' => '5.000',
        ];
    }
}
