<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Product images — upload, ordering, the single primary rule, and deletion.
 *
 * Every test fakes the media disk, so nothing touches real storage or S3.
 *
 * Arrange - Act - Assert throughout.
 */
class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = (string) config('media.disk');
        Storage::fake($this->disk);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        $user = User::factory()->create();

        // Acts as an administrator. Endpoints are permission-guarded, and these tests are about
        // the feature rather than who may reach it — authorization has its own suites in
        // RoleTest and RoleManagementTest.
        Role::findOrCreate(RoleName::Admin->value, 'web');
        $user->syncRoles([RoleName::Admin->value]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private function jpeg(string $name = 'bag.jpg', int $width = 800, int $height = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $width, $height);
    }

    // ─────────────────────────── upload ───────────────────────────

    public function test_upload_stores_the_file_and_records_it(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->jpeg()]);

        // Assert
        $response->assertCreated()
            ->assertJson(['status' => true, 'message' => 'تم رفع الصورة بنجاح'])
            ->assertJsonStructure([
                'data' => ['id', 'url', 'alt_text', 'is_primary', 'sort_order', 'mime_type', 'size_bytes', 'width_px', 'height_px'],
            ]);

        $image = ProductImage::query()->firstOrFail();
        Storage::disk($this->disk)->assertExists($image->path);
        $this->assertSame($product->id, $image->product_id);
    }

    public function test_upload_records_the_disk_it_was_written_to(): void
    {
        // Arrange — this is what makes a later move to S3 safe for existing rows.
        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act
        $this->withHeaders($headers)
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->jpeg()])
            ->assertCreated();

        // Assert
        $this->assertSame($this->disk, ProductImage::query()->firstOrFail()->disk);
    }

    public function test_upload_captures_the_dimensions_and_size(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->post(
            "/api/v1/products/{$product->id}/images",
            ['image' => $this->jpeg('wide.jpg', 1024, 512)],
        );

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.width_px', 1024)
            ->assertJsonPath('data.height_px', 512);
        $this->assertGreaterThan(0, $response->json('data.size_bytes'));
    }

    public function test_upload_does_not_keep_the_original_filename_as_the_stored_path(): void
    {
        // Arrange — two products uploading "logo.jpg" must not collide, and a client must not
        // get to choose where the file lands.
        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act
        $this->withHeaders($headers)->post(
            "/api/v1/products/{$product->id}/images",
            ['image' => $this->jpeg('logo.jpg')],
        )->assertCreated();

        // Assert
        $image = ProductImage::query()->firstOrFail();
        $this->assertStringNotContainsString('logo.jpg', $image->path);
        $this->assertSame('logo.jpg', $image->original_filename);
        $this->assertStringStartsWith("products/{$product->id}/", $image->path);
    }

    public function test_upload_accepts_an_alt_text(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->post(
            "/api/v1/products/{$product->id}/images",
            ['image' => $this->jpeg(), 'alt_text' => 'كيس شحن أبيض'],
        );

        // Assert
        $response->assertCreated()->assertJsonPath('data.alt_text', 'كيس شحن أبيض');
    }

    /**
     * @param  \Closure():UploadedFile|null  $file
     */
    #[DataProvider('invalidUploadCases')]
    public function test_upload_rejects_invalid_files(string $case): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();

        $payload = match ($case) {
            'missing' => [],
            'not an image' => ['image' => UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf')],
            'disallowed image type' => ['image' => UploadedFile::fake()->image('icon.gif')],
            'too large' => ['image' => UploadedFile::fake()->image('huge.jpg')->size(
                (int) config('media.product_images.max_kilobytes') + 1,
            )],
        };

        // Act
        $response = $this->withHeaders($headers)->post("/api/v1/products/{$product->id}/images", $payload);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('image');
        $this->assertDatabaseCount('product_images', 0);
    }

    public function test_upload_refuses_a_product_that_is_already_full(): void
    {
        // Arrange — a product carrying exactly the cap. The number is read from config rather
        // than typed, so raising the cap does not turn this into a test of a stale figure.
        $cap = (int) config('media.product_images.max_per_product');
        $product = Product::factory()->create();
        ProductImage::factory()->count($cap)->for($product)->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->post(
            "/api/v1/products/{$product->id}/images",
            ['image' => $this->jpeg()],
        );

        // Assert — refused, and the cap said in the message: "احذف صورة" is only actionable
        // advice if the reader knows how many they are allowed.
        $response->assertStatus(422)->assertJsonValidationErrors('image');
        $this->assertStringContainsString(
            (string) $cap,
            $response->json('errors.image.0'),
        );
        $this->assertSame($cap, $product->images()->count());
    }

    public function test_upload_accepts_the_last_slot_before_the_cap(): void
    {
        // Arrange — one below the cap, which must still be allowed. Guards the off-by-one that
        // would refuse the fifth photo on a limit of five.
        $cap = (int) config('media.product_images.max_per_product');
        $product = Product::factory()->create();
        ProductImage::factory()->count($cap - 1)->for($product)->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->post(
            "/api/v1/products/{$product->id}/images",
            ['image' => $this->jpeg()],
        );

        // Assert
        $response->assertCreated();
        $this->assertSame($cap, $product->images()->count());
    }

    public function test_the_cap_counts_only_this_product(): void
    {
        // Arrange — a full *other* product must not spend this one's allowance. The scoped()
        // route makes cross-product ids a 404; this is the matching rule for the count.
        $cap = (int) config('media.product_images.max_per_product');
        $other = Product::factory()->create();
        ProductImage::factory()->count($cap)->for($other)->create();

        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->post(
            "/api/v1/products/{$product->id}/images",
            ['image' => $this->jpeg()],
        );

        // Assert
        $response->assertCreated();
        $this->assertSame(1, $product->images()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUploadCases(): array
    {
        return [
            'missing' => ['missing'],
            'not an image' => ['not an image'],
            'disallowed image type' => ['disallowed image type'],
            'too large' => ['too large'],
        ];
    }

    public function test_upload_requires_authentication(): void
    {
        // Arrange
        $product = Product::factory()->create();

        // Act
        $response = $this->post("/api/v1/products/{$product->id}/images", ['image' => $this->jpeg()]);

        // Assert
        $response->assertStatus(401);
    }

    // ─────────────────────────── the primary image ───────────────────────────

    public function test_the_first_image_becomes_the_primary_one(): void
    {
        // Arrange — a catalogue entry with photos but no thumbnail would be a gap in the grid.
        $product = Product::factory()->create();
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->jpeg()]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.is_primary', true);
    }

    public function test_a_second_image_is_not_primary_by_default(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();
        $this->withHeaders($headers)->post("/api/v1/products/{$product->id}/images", ['image' => $this->jpeg()]);

        // Act
        $response = $this->withHeaders($headers)
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->jpeg('second.jpg')]);

        // Assert
        $response->assertCreated()->assertJsonPath('data.is_primary', false);
        $this->assertSame(1, ProductImage::query()->where('is_primary', true)->count());
    }

    public function test_uploading_with_is_primary_demotes_the_previous_one(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();
        $first = $this->withHeaders($headers)
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->jpeg()])
            ->json('data.id');

        // Act
        $second = $this->withHeaders($headers)->post(
            "/api/v1/products/{$product->id}/images",
            ['image' => $this->jpeg('second.jpg'), 'is_primary' => true],
        );

        // Assert
        $second->assertCreated()->assertJsonPath('data.is_primary', true);
        $this->assertDatabaseHas('product_images', ['id' => $first, 'is_primary' => false]);
        $this->assertSame(1, ProductImage::query()->where('is_primary', true)->count());
    }

    public function test_promoting_an_image_demotes_the_current_primary(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $primary = ProductImage::factory()->primary()->create(['product_id' => $product->id]);
        $other = ProductImage::factory()->create(['product_id' => $product->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/products/{$product->id}/images/{$other->id}",
            ['is_primary' => true],
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.is_primary', true);
        $this->assertDatabaseHas('product_images', ['id' => $primary->id, 'is_primary' => false]);
        $this->assertSame(1, ProductImage::query()->where('is_primary', true)->count());
    }

    public function test_the_database_refuses_two_primary_images_for_one_product(): void
    {
        // Arrange — application code can be bypassed; the partial unique index cannot.
        $product = Product::factory()->create();
        ProductImage::factory()->primary()->create(['product_id' => $product->id]);

        // Assert
        $this->expectException(QueryException::class);

        // Act
        ProductImage::factory()->primary()->create(['product_id' => $product->id]);
    }

    public function test_two_different_products_may_each_have_a_primary_image(): void
    {
        // Arrange — the uniqueness is per product, not global.
        $first = Product::factory()->create();
        $second = Product::factory()->create();

        // Act
        ProductImage::factory()->primary()->create(['product_id' => $first->id]);
        ProductImage::factory()->primary()->create(['product_id' => $second->id]);

        // Assert
        $this->assertSame(2, ProductImage::query()->where('is_primary', true)->count());
    }

    // ─────────────────────────── update ───────────────────────────

    public function test_update_changes_the_caption_and_ordering(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $image = ProductImage::factory()->create(['product_id' => $product->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/products/{$product->id}/images/{$image->id}",
            ['alt_text' => 'وصف جديد', 'sort_order' => 5],
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.alt_text', 'وصف جديد')
            ->assertJsonPath('data.sort_order', 5);
    }

    public function test_update_cannot_demote_the_primary_without_naming_a_replacement(): void
    {
        // Arrange — a product must always keep exactly one primary image.
        $product = Product::factory()->create();
        $image = ProductImage::factory()->primary()->create(['product_id' => $product->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/products/{$product->id}/images/{$image->id}",
            ['is_primary' => false],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('is_primary');
        $this->assertDatabaseHas('product_images', ['id' => $image->id, 'is_primary' => true]);
    }

    public function test_an_image_cannot_be_reached_through_another_product(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $foreignImage = ProductImage::factory()->create();
        $headers = $this->auth();

        // Act — scoped route binding resolves {image} within {product}.
        $response = $this->withHeaders($headers)->patchJson(
            "/api/v1/products/{$product->id}/images/{$foreignImage->id}",
            ['alt_text' => 'اختراق'],
        );

        // Assert
        $response->assertNotFound();
        $this->assertDatabaseHas('product_images', ['id' => $foreignImage->id, 'alt_text' => $foreignImage->alt_text]);
    }

    // ─────────────────────────── delete ───────────────────────────

    public function test_delete_removes_the_record_and_the_stored_file(): void
    {
        // Arrange — two photos, because deleting the only one is refused. This test is about
        // what deletion does to the row and the file, not about the last-photo rule.
        $product = Product::factory()->create();
        $headers = $this->auth();
        ProductImage::factory()->primary()->create(['product_id' => $product->id]);
        $uploaded = $this->withHeaders($headers)
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->jpeg()])
            ->json('data.id');
        $path = ProductImage::query()->findOrFail($uploaded)->path;
        Storage::disk($this->disk)->assertExists($path);

        // Act
        $response = $this->withHeaders($headers)
            ->deleteJson("/api/v1/products/{$product->id}/images/{$uploaded}");

        // Assert
        // The row is soft deleted so the audit trail keeps it; the *file* is removed for real,
        // because object storage has no deleted_at and keeping every replaced photo grows without
        // bound.
        $response->assertOk()->assertJson(['status' => true, 'data' => null]);
        $this->assertSoftDeleted('product_images', ['id' => $uploaded]);
        $this->assertSame(1, ProductImage::query()->count());
        Storage::disk($this->disk)->assertMissing($path);
    }

    public function test_deleting_the_primary_image_promotes_another(): void
    {
        // Arrange — a product with photos left should never lose its thumbnail.
        $product = Product::factory()->create();
        $primary = ProductImage::factory()->primary()->create(['product_id' => $product->id]);
        $other = ProductImage::factory()->create(['product_id' => $product->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->deleteJson("/api/v1/products/{$product->id}/images/{$primary->id}");

        // Assert
        $response->assertOk();
        $this->assertDatabaseHas('product_images', ['id' => $other->id, 'is_primary' => true]);
    }

    public function test_deleting_the_last_image_is_refused(): void
    {
        // Arrange — every product carries at least one photo, and that is a standing invariant
        // rather than a rule that only applies while the product is being created. Without this
        // the route from "every product has a photo" back to "this one doesn't" is one tap long.
        $product = Product::factory()->create();
        $only = ProductImage::factory()->primary()->create(['product_id' => $product->id]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)
            ->deleteJson("/api/v1/products/{$product->id}/images/{$only->id}");

        // Assert
        $response->assertStatus(422)->assertJson(['status' => false]);
        $this->assertDatabaseHas('product_images', ['id' => $only->id, 'deleted_at' => null]);
        $this->assertSame(1, ProductImage::query()->count());
    }

    public function test_the_last_image_can_be_replaced_by_uploading_first(): void
    {
        // Arrange — the way out of the rule above. Upload the replacement, then remove the old
        // one; at no point does the product have nothing.
        $product = Product::factory()->create();
        $original = ProductImage::factory()->primary()->create(['product_id' => $product->id]);
        $headers = $this->auth();

        // Act
        $this->withHeaders($headers)
            ->post("/api/v1/products/{$product->id}/images", ['image' => $this->jpeg('new.jpg')])
            ->assertCreated();
        $response = $this->withHeaders($headers)
            ->deleteJson("/api/v1/products/{$product->id}/images/{$original->id}");

        // Assert
        $response->assertOk();
        $this->assertSame(1, ProductImage::query()->count());
        $this->assertSame(1, ProductImage::query()->where('is_primary', true)->count());
    }

    public function test_delete_requires_authentication(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $image = ProductImage::factory()->create(['product_id' => $product->id]);

        // Act
        $response = $this->deleteJson("/api/v1/products/{$product->id}/images/{$image->id}");

        // Assert
        $response->assertStatus(401);
    }

    // ─────────────────────────── exposure on the product ───────────────────────────

    public function test_a_product_lists_its_images_primary_first(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $second = ProductImage::factory()->create(['product_id' => $product->id, 'sort_order' => 2]);
        $primary = ProductImage::factory()->primary()->create(['product_id' => $product->id, 'sort_order' => 9]);
        $headers = $this->auth();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/products/{$product->id}");

        // Assert — primary leads even though its sort_order is higher.
        $response->assertOk()->assertJsonCount(2, 'data.images');
        $this->assertSame(
            [$primary->id, $second->id],
            array_column($response->json('data.images'), 'id'),
        );
    }

    public function test_every_image_exposes_a_usable_url(): void
    {
        // Arrange
        $product = Product::factory()->create();
        $headers = $this->auth();
        $this->withHeaders($headers)->post("/api/v1/products/{$product->id}/images", ['image' => $this->jpeg()]);

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/products/{$product->id}");

        // Assert
        $response->assertOk();
        $url = $response->json('data.images.0.url');
        $this->assertIsString($url);
        $this->assertNotSame('', $url);
    }

    public function test_force_deleting_a_product_cascades_to_its_images(): void
    {
        // Arrange — the database-level guarantee, which only a real delete exercises. A soft
        // delete leaves the product row in place, so nothing cascades.
        $product = Product::factory()->create();
        ProductImage::factory()->count(2)->create(['product_id' => $product->id]);

        // Act
        $product->forceDelete();

        // Assert
        $this->assertSame(0, ProductImage::withTrashed()->count());
    }
}
