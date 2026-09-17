<?php

namespace Database\Factories;

use App\Domain\Investor\Enums\WalletEntryType;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorWalletEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvestorWalletEntry>
 */
class InvestorWalletEntryFactory extends Factory
{
    /** @var class-string<InvestorWalletEntry> */
    protected $model = InvestorWalletEntry::class;

    /**
     * A deposit by default — the only entry that needs nothing else to exist first.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'investor_id' => Investor::factory(),
            'investor_deal_id' => null,
            'type' => WalletEntryType::Deposit,
            'amount' => '10000.00',
            'method' => 'cash',
            'occurred_at' => now(),
        ];
    }
}
