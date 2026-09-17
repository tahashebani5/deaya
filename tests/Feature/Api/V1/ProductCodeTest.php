<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every product carries a short code — P1, P2, P3 — printed beside its name in the app.
 *
 * It exists so a bag can be named without saying the whole Arabic name: "P4، مقاس 45*50" over
 * the phone is unambiguous where "أكياس يد داخلية" and "أكياس يد خارجية" differ by one word.
 *
 * The code is allocated by the server, never sent by the client, never edited, and never
 * reused. It always equals `P` + the row's id, which is what makes it usable in support: a
 * customer quoting P7 is quoting product 7.
 *
 * Arrange - Act - Assert throughout.
 */
class ProductCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Creating a product now carries a photo, so the disk is faked rather than written to.
        Storage::fake((string) config('media.disk'));
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        $user = User::factory()->create();

        Role::findOrCreate(RoleName::Admin->value, 'web');
        $user->syncRoles([RoleName::Admin->value]);

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    private ?int $categoryId = null;

    /**
     * The catalogue heading every product now needs — «التصنيف».
     *
     * Made once per test and reused: the field is required on create *and* on update, so a
     * fresh row per call would leave a trail of categories nothing points at.
     */
    private function categoryId(): int
    {
        return $this->categoryId ??= ProductCategory::factory()->create()->id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'product_category_id' => $this->categoryId(),
            'slug' => 'shipping-bag',
            'name' => 'أكياس الشحن',
            'pricing_unit' => 'piece',
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 100,
            // Required on create. Incidental to the code, but the request is refused without it.
            'image' => UploadedFile::fake()->image('bag.jpg'),
        ], $overrides);
    }

    /**
     * The same body without the photo, for the one test here that updates.
     *
     * Updating is still JSON, and an UploadedFile cannot be encoded into a JSON body — it would
     * arrive as `{}` and be silently ignored, which is exactly the kind of quiet nonsense that
     * makes a passing test worthless.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payloadWithoutImage(array $overrides = []): array
    {
        $payload = $this->payload($overrides);
        unset($payload['image']);

        return $payload;
    }

    public function test_a_new_product_is_given_a_code_that_matches_its_id(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->post('/api/v1/products', $this->payload(), $headers);

        // Assert
        $response->assertCreated();

        $id = $response->json('data.id');
        $this->assertSame('P'.$id, $response->json('data.code'));
        $this->assertDatabaseHas('products', ['id' => $id, 'code' => 'P'.$id]);
    }

    public function test_the_code_cannot_be_chosen_by_the_client(): void
    {
        // Arrange — a request that tries to name the product itself.
        $headers = $this->auth();

        // Act
        $response = $this->post(
            '/api/v1/products',
            $this->payload(['code' => 'P999']),
            $headers,
        );

        // Assert — the server's answer wins, and nothing anywhere holds the client's.
        $response->assertCreated();
        $this->assertSame('P'.$response->json('data.id'), $response->json('data.code'));
        $this->assertDatabaseMissing('products', ['code' => 'P999']);
    }

    public function test_editing_a_product_never_changes_its_code(): void
    {
        // Arrange — a code that moved would break every quote that already went out under it.
        $headers = $this->auth();
        $created = $this->post('/api/v1/products', $this->payload(), $headers);
        $code = $created->json('data.code');

        // Act
        $response = $this->putJson(
            '/api/v1/products/'.$created->json('data.id'),
            $this->payloadWithoutImage(['name' => 'أكياس الشحن الكبيرة', 'code' => 'P999']),
            $headers,
        );

        // Assert
        $response->assertOk();
        $this->assertSame($code, $response->json('data.code'));
    }

    public function test_two_products_never_share_a_code(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $first = $this->post('/api/v1/products', $this->payload(), $headers);
        $second = $this->post(
            '/api/v1/products',
            $this->payload(['slug' => 'paper-bag', 'name' => 'أكياس ورقية']),
            $headers,
        );

        // Assert
        $this->assertNotSame($first->json('data.code'), $second->json('data.code'));
    }

    public function test_the_code_is_published_with_every_product(): void
    {
        // Arrange
        $headers = $this->auth();
        $product = Product::factory()->create();

        // Act
        $response = $this->getJson('/api/v1/products', $headers);

        // Assert — the list is where the app reads it, so it has to be there and not only on
        // the single-product endpoint.
        $response->assertOk()->assertJsonPath('data.0.code', $product->code);
    }

    public function test_a_product_created_outside_the_api_still_gets_a_code(): void
    {
        // Arrange — the seeder and the factory both bypass CreateProduct, and the column is
        // NOT NULL. The guarantee has to live somewhere neither of them can skip.
        // Act
        $product = Product::factory()->create();

        // Assert
        $this->assertSame('P'.$product->id, $product->code);
    }
}
