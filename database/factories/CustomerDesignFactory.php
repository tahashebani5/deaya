<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customer\Enums\DesignKind;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CustomerDesign>
 */
class CustomerDesignFactory extends Factory
{
    protected $model = CustomerDesign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = Str::uuid()->toString();

        return [
            'customer_id' => Customer::factory(),
            'disk' => 'local',
            'path' => "customer-designs/1/{$uuid}.png",
            'original_filename' => 'logo.png',
            'mime_type' => 'image/png',
            'kind' => DesignKind::Image,
            'size_bytes' => 512_000,
            // Unique per row: the partial index refuses two live designs with the same checksum
            // for one customer, and a fixed value here would fail the second `count(2)`.
            'checksum' => hash('sha256', $uuid),
            'width_px' => 1200,
            'height_px' => 1600,
            'label' => 'تصميم الكيس الكبير',
            'notes' => null,
        ];
    }

    public function pdf(): static
    {
        return $this->state(fn () => [
            'original_filename' => 'artwork.pdf',
            'mime_type' => 'application/pdf',
            'kind' => DesignKind::Pdf,
            // A PDF has pages, not pixels.
            'width_px' => null,
            'height_px' => null,
        ]);
    }
}
