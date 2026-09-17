<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Carrier\Models\NawrisParcel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NawrisParcel>
 */
class NawrisParcelFactory extends Factory
{
    protected $model = NawrisParcel::class;

    /**
     * Sequence-generated rather than random, because `code` and `reference` both carry unique
     * indexes: a chance collision failing an unrelated test is a miserable bug to find.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => $this->sequenceValue('NP'),
            'reference' => $this->sequenceValue('ref'),
            'bar_code' => $this->sequenceValue('bar'),
            'government' => '5',
            'area' => null,
            'amount_to_collect' => '100.00',
            'delivery_price_deducted' => '20.00',
            'collected_amount' => null,
            'remote_status_code' => null,
            'remote_status_text' => null,
            'shipping_company_id' => null,
            'dispatched_at' => now(),
            'closed_at' => null,
        ];
    }

    /** Finished — delivered, returned or written off. */
    public function closed(): self
    {
        return $this->state(fn () => ['closed_at' => now()]);
    }

    private function sequenceValue(string $prefix): string
    {
        static $n = 0;

        return $prefix.'-'.str_pad((string) (++$n), 6, '0', STR_PAD_LEFT);
    }
}
