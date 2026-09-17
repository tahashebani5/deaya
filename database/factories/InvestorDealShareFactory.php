<?php

namespace Database\Factories;

use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorDeal;
use App\Domain\Investor\Models\InvestorDealShare;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestorDealShare>
 */
class InvestorDealShareFactory extends Factory
{
    /** @var class-string<InvestorDealShare> */
    protected $model = InvestorDealShare::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'investor_deal_id' => InvestorDeal::factory(),
            'investor_id' => Investor::factory(),
            'committed_amount' => '10000.00',
            'share_percent' => '100.0000',
            'joined_at' => now(),
        ];
    }
}
