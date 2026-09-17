<?php

namespace Database\Factories;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealSupply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestorDealSupply>
 */
class InvestorDealSupplyFactory extends Factory
{
    /** @var class-string<InvestorDealSupply> */
    protected $model = InvestorDealSupply::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'investor_deal_id' => InvestorDeal::factory(),
            'source_type' => AuditSubject::PurchaseOrder->value,
            'source_id' => 1,
            'stock_item_id' => StockItem::factory(),
        ];
    }
}
