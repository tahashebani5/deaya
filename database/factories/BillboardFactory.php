<?php

namespace Database\Factories;

use App\Domain\Marketing\Models\Billboard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Billboard>
 */
class BillboardFactory extends Factory
{
    /** @var class-string<Billboard> */
    protected $model = Billboard::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => 'عرض '.fake()->word(),
            // The public disk, like a product photo and unlike a customer's design: a banner is
            // the business's own marketing.
            'disk' => 'public',
            'path' => 'billboards/'.Str::uuid()->toString().'.jpg',
            'original_filename' => 'promo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 240_000,
            'width_px' => 1200,
            'height_px' => 500,
            'sort_order' => 0,
            // Showing, with both ends of the window open — the ordinary case, and the one a test
            // about *not* showing has to state its way out of.
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
