<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    /** @var class-string<ProductImage> */
    protected $model = ProductImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'disk' => (string) config('media.disk'),
            'path' => 'products/'.Str::uuid()->toString().'.jpg',
            'original_filename' => 'bag.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 204_800,
            'width_px' => 1200,
            'height_px' => 900,
            'alt_text' => null,
            // Left false by default: the database allows only one primary per product, so a
            // factory that defaulted to true could not create two images for the same product.
            'is_primary' => false,
            'sort_order' => 0,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }
}
