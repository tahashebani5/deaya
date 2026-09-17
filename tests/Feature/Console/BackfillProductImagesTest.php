<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `catalog:backfill-images` — the one-off that photographs the catalogue that predates the
 * photo requirement.
 *
 * Arrange - Act - Assert throughout.
 */
class BackfillProductImagesTest extends TestCase
{
    use RefreshDatabase;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = (string) config('media.disk');
        Storage::fake($this->disk);
    }

    public function test_it_gives_every_product_without_a_photo_a_primary_image(): void
    {
        // Arrange
        $products = Product::factory()->count(3)->create();

        // Act
        $this->artisan('catalog:backfill-images')->assertSuccessful();

        // Assert
        $this->assertSame(3, ProductImage::query()->count());

        foreach ($products as $product) {
            $image = ProductImage::query()->where('product_id', $product->id)->firstOrFail();
            $this->assertTrue($image->is_primary, "Product {$product->code} should have a primary image.");
            Storage::disk($this->disk)->assertExists($image->path);
        }
    }

    public function test_each_product_gets_its_own_copy_of_the_file(): void
    {
        // Arrange — a shared path would mean deleting one product's photo breaks every other
        // product pointing at the same object, because the file is removed for real.
        Product::factory()->count(3)->create();

        // Act
        $this->artisan('catalog:backfill-images')->assertSuccessful();

        // Assert
        $paths = ProductImage::query()->pluck('path')->all();
        $this->assertCount(3, array_unique($paths));
    }

    public function test_it_leaves_a_product_that_already_has_a_photo_alone(): void
    {
        // Arrange
        $photographed = Product::factory()->create();
        $existing = ProductImage::factory()->primary()->create(['product_id' => $photographed->id]);
        $bare = Product::factory()->create();

        // Act
        $this->artisan('catalog:backfill-images')->assertSuccessful();

        // Assert
        $this->assertSame(1, ProductImage::query()->where('product_id', $photographed->id)->count());
        $this->assertSame($existing->path, $existing->refresh()->path);
        $this->assertSame(1, ProductImage::query()->where('product_id', $bare->id)->count());
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        // Arrange
        Product::factory()->count(2)->create();

        // Act
        $this->artisan('catalog:backfill-images')->assertSuccessful();
        $this->artisan('catalog:backfill-images')->assertSuccessful();

        // Assert
        $this->assertSame(2, ProductImage::query()->count());
    }

    public function test_it_reports_a_missing_source_rather_than_writing_half_a_catalogue(): void
    {
        // Arrange
        Product::factory()->count(2)->create();

        // Act
        $this->artisan('catalog:backfill-images', ['--source' => '/nowhere/missing.png'])
            ->assertFailed();

        // Assert
        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_it_succeeds_quietly_when_every_product_is_already_photographed(): void
    {
        // Arrange
        $product = Product::factory()->create();
        ProductImage::factory()->primary()->create(['product_id' => $product->id]);

        // Act & Assert
        $this->artisan('catalog:backfill-images')
            ->expectsOutputToContain('Every product already has a photo.')
            ->assertSuccessful();
    }
}
