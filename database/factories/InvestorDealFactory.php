<?php

namespace Database\Factories;

use App\Domain\Investor\Enums\DealStatus;
use App\Domain\Investor\Models\InvestorDeal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestorDeal>
 */
class InvestorDealFactory extends Factory
{
    /** @var class-string<InvestorDeal> */
    protected $model = InvestorDeal::class;

    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => DealStatus::Draft,
            'investor_profit_share_percent' => '50.00',
            'opened_on' => now()->toDateString(),
        ];
    }

    /** A deal that has been opened and may take stock. */
    public function open(): self
    {
        return $this->state(fn () => [
            'status' => DealStatus::Open,
            'opened_at' => now(),
        ]);
    }
}
