<?php

namespace Database\Factories;

use App\Domain\Investor\Models\Investor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Investor>
 */
class InvestorFactory extends Factory
{
    /** @var class-string<Investor> */
    protected $model = Investor::class;

    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'name' => 'مستثمر '.$n,
            'phone' => '091'.str_pad((string) (1000000 + $n), 7, '0', STR_PAD_LEFT),
            'is_active' => true,
        ];
    }
}
