<?php

namespace Database\Factories;

use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Shortage\Enums\SupplyKind;
use App\Domain\Shortage\Models\Shortage;
use App\Domain\Shortage\Models\ShortageSupply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShortageSupply>
 */
class ShortageSupplyFactory extends Factory
{
    /** @var class-string<ShortageSupply> */
    protected $model = ShortageSupply::class;

    /**
     * **A row made here does not restate its shortage's totals.**
     *
     * `RecalculateShortageTotals` is what keeps `supplied_quantity` and `total_paid` in step with
     * this table, and it runs from the Action rather than from a model event — deliberately, so
     * that the ledger has one writer and one restatement rather than an observer firing on every
     * seeder row. A test that needs the totals to agree records its supply through
     * `ShortageService::recordSupply()`; one that only needs a row to exist uses this.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shortage_id' => Shortage::factory(),
            'kind' => SupplyKind::Purchased,
            'quantity' => '10.000',
            'amount' => '250.00',
            'method' => PaymentMethod::Cash,
            'occurred_on' => now()->toDateString(),
        ];
    }

    /**
     * The sync's own row: a quantity that arrived through the order screen, with no money on it.
     *
     * The CHECK on the table refuses an amount here, so this is the only correct shape — stated
     * as a state rather than left for each test to remember.
     */
    public function resolvedExternally(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => SupplyKind::ResolvedExternally,
            'amount' => null,
            'method' => null,
        ]);
    }
}
