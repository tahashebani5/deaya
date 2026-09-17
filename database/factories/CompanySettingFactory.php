<?php

namespace Database\Factories;

use App\Domain\Settings\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySetting>
 */
class CompanySettingFactory extends Factory
{
    /** @var class-string<CompanySetting> */
    protected $model = CompanySetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'investor_profit_share_percent' => '50.00',
        ];
    }
}
