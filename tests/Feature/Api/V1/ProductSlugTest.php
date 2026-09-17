<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The slug is the product's URL-safe identifier, and nobody types it.
 *
 * Asking a shop for a lowercase Latin, hyphenated, globally-unique string — for a product they
 * called أكياس الشحن — is asking them to invent something in a language the rest of the form is
 * not in, and to keep it unique across a catalogue they cannot see. The server knows the name
 * and it knows the code it just allocated, which is everything the slug can be derived from.
 *
 * Arrange - Act - Assert throughout.
 */
class ProductSlugTest extends TestCase
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
    private function bearerForManager(): array
    {
        $user = User::factory()->create();

        // Acts as an administrator, exactly as ProductTest does: these tests are about the slug,
        // not about who may create a product — authorization has its own suites.
        Role::findOrCreate(RoleName::Admin->value, 'web');
        $user->syncRoles([RoleName::Admin->value]);

        return ['Authorization' => 'Bearer '.$user->createToken('test-device')->plainTextToken];
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
            'name' => 'أكياس الشحن',
            'pricing_unit' => 'piece',
            'pricing_mode' => 'tiered',
            'min_order_quantity' => '100',
            // Required on create, so every body here carries one. The photo is incidental to the
            // slug — it is here to make the request valid, not because the slug depends on it.
            'image' => UploadedFile::fake()->image('bag.jpg'),
        ], $overrides);
    }

    public function test_a_product_created_without_a_slug_is_given_one(): void
    {
        // Arrange
        $payload = $this->payload();

        // Act
        $response = $this->post('/api/v1/products', $payload, $this->bearerForManager());

        // Assert
        $response->assertCreated();
        $this->assertNotEmpty($response->json('data.slug'));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $response->json('data.slug'));
    }

    public function test_an_arabic_name_is_transliterated_rather_than_discarded(): void
    {
        // Arrange — `Str::slug` romanises before it strips, so an Arabic name does not reduce to
        // nothing the way ASCII-only stripping would. The exact spelling is asserted because it
        // is what the URLs of this catalogue will look like, and a change in it is a change
        // worth being told about.
        $payload = $this->payload(['name' => 'أكياس الشحن']);

        // Act
        $response = $this->post('/api/v1/products', $payload, $this->bearerForManager());

        // Assert
        $response->assertCreated()->assertJsonPath('data.slug', 'akyas-alshhn');
    }

    public function test_a_name_with_nothing_to_romanise_falls_back_to_the_product_code(): void
    {
        // Arrange — a name of symbols leaves an empty string behind, and the column is NOT NULL
        // with a unique index. The code is the one identifier that always exists and is always
        // unique.
        $payload = $this->payload(['name' => '★★']);

        // Act
        $response = $this->post('/api/v1/products', $payload, $this->bearerForManager());

        // Assert
        $response->assertCreated();
        $this->assertSame(
            strtolower((string) $response->json('data.code')),
            $response->json('data.slug'),
        );
    }

    public function test_a_latin_name_becomes_a_readable_slug(): void
    {
        // Arrange
        $payload = $this->payload(['name' => 'Shipping Bag']);

        // Act
        $response = $this->post('/api/v1/products', $payload, $this->bearerForManager());

        // Assert
        $response->assertCreated()->assertJsonPath('data.slug', 'shipping-bag');
    }

    public function test_a_generated_slug_that_is_taken_is_made_unique(): void
    {
        // Arrange
        Product::factory()->create(['slug' => 'shipping-bag']);

        // Act
        $response = $this->post(
            '/api/v1/products',
            $this->payload(['name' => 'Shipping Bag']),
            $this->bearerForManager(),
        );

        // Assert — suffixed with the code, which is unique by construction, so this can never
        // collide however many times the same name is used.
        $response->assertCreated();
        $this->assertSame(
            'shipping-bag-'.strtolower((string) $response->json('data.code')),
            $response->json('data.slug'),
        );
    }

    public function test_a_slug_sent_by_a_client_is_still_honoured(): void
    {
        // Arrange — the field is no longer asked for on the form, but the API keeps accepting it:
        // an import or a fixture that carries a deliberate slug must not have it overwritten.
        $payload = $this->payload(['slug' => 'deliberate-slug']);

        // Act
        $response = $this->post('/api/v1/products', $payload, $this->bearerForManager());

        // Assert
        $response->assertCreated()->assertJsonPath('data.slug', 'deliberate-slug');
    }

    public function test_a_slug_sent_by_a_client_is_still_validated(): void
    {
        // Arrange — optional does not mean unchecked.
        $payload = $this->payload(['slug' => 'Not A Slug']);

        // Act
        $response = $this->post('/api/v1/products', $payload, $this->bearerForManager());

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('slug');
    }

    public function test_two_products_with_the_same_arabic_name_both_get_a_slug(): void
    {
        // Arrange
        $headers = $this->bearerForManager();

        // Act
        $first = $this->post('/api/v1/products', $this->payload(), $headers);
        $second = $this->post(
            '/api/v1/products',
            $this->payload(['name' => 'أكياس الشحن']),
            $headers,
        );

        // Assert — nothing about a name has to be unique, so two identical ones must still land.
        $first->assertCreated();
        $second->assertCreated();
        $this->assertNotSame($first->json('data.slug'), $second->json('data.slug'));
    }
}
