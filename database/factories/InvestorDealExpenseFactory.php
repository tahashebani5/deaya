<?php

namespace Database\Factories;

use App\Domain\Investor\Enums\DealExpenseKind;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealExpense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestorDealExpense>
 */
class InvestorDealExpenseFactory extends Factory
{
    /** @var class-string<InvestorDealExpense> */
    protected $model = InvestorDealExpense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'investor_deal_id' => InvestorDeal::factory(),
            'kind' => DealExpenseKind::Shipping,
            'name' => 'فاتورة شحن',
            'amount' => '500.00',
            'is_landed' => false,
            'incurred_on' => now()->toDateString(),
        ];
    }
}
