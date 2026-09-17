<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Marketing\Models\Billboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The billboard — what the shop puts in front of the customer app's home screen.
 *
 * **Two audiences, one table, and the whole feature is the word «live».** Staff manage every
 * row, scheduled or expired or switched off; the customer is only ever sent the ones showing
 * right now. `test_the_app_is_sent_only_what_is_showing_now` is what holds that line, and it is
 * the test to read first — a banner whose window has closed is not hidden by the app, it never
 * leaves the server.
 *
 * Arrange - Act - Assert throughout.
 */
class BillboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @return array<string, string>
     */
    private function staff(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission->value);
        }

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    private function customerBearer(): array
    {
        $customer = Customer::factory()->registered()->create();

        return ['Authorization' => 'Bearer '.$customer->createToken('app')->plainTextToken];
    }

    private function banner(): UploadedFile
    {
        return UploadedFile::fake()->image('promo.jpg', 1200, 500);
    }

    // ─────────────────────── what the app is sent ───────────────────────

    /**
     * **The test the feature exists for.** Four rows that must not travel, each for a different
     * reason, and one that must.
     */
    public function test_the_app_is_sent_only_what_is_showing_now(): void
    {
        // Arrange
        $showing = Billboard::factory()->create(['sort_order' => 1]);
        $switchedOff = Billboard::factory()->create(['is_active' => false]);
        $notYet = Billboard::factory()->create(['starts_at' => now()->addWeek()]);
        $finished = Billboard::factory()->create(['ends_at' => now()->subDay()]);

        // Act
        $response = $this->withHeaders($this->customerBearer())->getJson('/api/v1/client/billboards');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $showing->id);

        $ids = array_column((array) $response->json('data'), 'id');
        $this->assertNotContains($switchedOff->id, $ids);
        $this->assertNotContains($notYet->id, $ids);
        $this->assertNotContains($finished->id, $ids);
    }

    /**
     * A window with both ends open is the ordinary case — most banners run until somebody takes
     * them down — so a null on either side must never read as "closed".
     */
    public function test_a_banner_with_no_window_is_always_showing(): void
    {
        // Arrange
        Billboard::factory()->create(['starts_at' => null, 'ends_at' => null]);

        // Act
        $response = $this->withHeaders($this->customerBearer())->getJson('/api/v1/client/billboards');

        // Assert
        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_carousel_is_in_the_order_staff_set(): void
    {
        // Arrange
        $third = Billboard::factory()->create(['sort_order' => 30]);
        $first = Billboard::factory()->create(['sort_order' => 10]);
        $second = Billboard::factory()->create(['sort_order' => 20]);

        // Act
        $response = $this->withHeaders($this->customerBearer())->getJson('/api/v1/client/billboards');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.2.id', $third->id);
    }

    public function test_a_banner_carries_its_image_and_where_it_leads(): void
    {
        // Arrange
        $product = Product::factory()->create();
        Billboard::factory()->create(['product_id' => $product->id]);

        // Act
        $response = $this->withHeaders($this->customerBearer())->getJson('/api/v1/client/billboards');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.target.type', 'product')
            ->assertJsonPath('data.0.target.product_id', $product->id);

        $this->assertIsString($response->json('data.0.image_url'));
    }

    /**
     * A banner that leads nowhere is a picture, and the app must be told that plainly rather
     * than left to infer it from two nulls.
     */
    public function test_a_banner_that_leads_nowhere_says_so(): void
    {
        // Arrange
        Billboard::factory()->create(['product_id' => null, 'external_url' => null]);

        // Act
        $response = $this->withHeaders($this->customerBearer())->getJson('/api/v1/client/billboards');

        // Assert
        $response->assertOk()->assertJsonPath('data.0.target.type', 'none');
    }

    public function test_the_app_is_never_told_the_schedule_or_the_housekeeping(): void
    {
        // Arrange
        Billboard::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

        // Act
        $response = $this->withHeaders($this->customerBearer())->getJson('/api/v1/client/billboards');

        // Assert
        $response->assertOk()
            ->assertDontSee('starts_at')
            ->assertDontSee('ends_at')
            ->assertDontSee('is_active')
            ->assertDontSee('sort_order');
    }

    public function test_the_billboard_needs_a_customer_token(): void
    {
        // Act
        $response = $this->getJson('/api/v1/client/billboards');

        // Assert
        $response->assertUnauthorized();
    }

    // ─────────────────────── managing them ───────────────────────

    public function test_a_banner_is_created_with_its_image(): void
    {
        // Arrange
        $headers = $this->staff(PermissionName::ManageBillboards);

        // Act
        $response = $this->withHeaders($headers)->post('/api/v1/billboards', [
            'image' => $this->banner(),
            'title' => 'عرض الجملة',
            'sort_order' => 5,
        ], ['Accept' => 'application/json']);

        // Assert
        $response->assertCreated()->assertJsonPath('data.title', 'عرض الجملة');
        $this->assertDatabaseHas('billboards', ['title' => 'عرض الجملة', 'sort_order' => 5]);
    }

    public function test_the_management_list_shows_the_ones_not_showing_too(): void
    {
        // Arrange
        $headers = $this->staff(PermissionName::ManageBillboards);
        Billboard::factory()->create();
        Billboard::factory()->create(['is_active' => false]);
        Billboard::factory()->create(['ends_at' => now()->subDay()]);

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/billboards');

        // Assert
        $response->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_a_banner_can_be_switched_off_without_being_removed(): void
    {
        // Arrange
        $headers = $this->staff(PermissionName::ManageBillboards);
        $banner = Billboard::factory()->create(['is_active' => true]);

        // Act
        $response = $this->withHeaders($headers)
            ->patchJson("/api/v1/billboards/{$banner->id}", ['is_active' => false]);

        // Assert
        $response->assertOk();
        $this->assertDatabaseHas('billboards', ['id' => $banner->id, 'is_active' => false]);
    }

    /**
     * Unlike a customer or a product, a banner *is* deletable: it is a poster, not a record
     * anything else points at.
     */
    public function test_a_banner_is_removable(): void
    {
        // Arrange
        $headers = $this->staff(PermissionName::ManageBillboards);
        $banner = Billboard::factory()->create();

        // Act
        $response = $this->withHeaders($headers)->deleteJson("/api/v1/billboards/{$banner->id}");

        // Assert
        $response->assertOk();
        $this->assertSoftDeleted('billboards', ['id' => $banner->id]);
    }

    /**
     * One tap, one destination. Two would mean the server deciding which wins, and a banner
     * whose behaviour depends on a precedence rule nobody wrote down.
     */
    public function test_a_banner_cannot_lead_to_a_product_and_a_link_at_once(): void
    {
        // Arrange
        $headers = $this->staff(PermissionName::ManageBillboards);
        $product = Product::factory()->create();

        // Act
        $response = $this->withHeaders($headers)->post('/api/v1/billboards', [
            'image' => $this->banner(),
            'product_id' => $product->id,
            'external_url' => 'https://daaya.ly/promo',
        ], ['Accept' => 'application/json']);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('external_url');
    }

    public function test_a_banner_cannot_point_at_a_product_that_does_not_exist(): void
    {
        // Arrange
        $headers = $this->staff(PermissionName::ManageBillboards);

        // Act
        $response = $this->withHeaders($headers)->post('/api/v1/billboards', [
            'image' => $this->banner(),
            'product_id' => 999999,
        ], ['Accept' => 'application/json']);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    public function test_the_image_is_required_on_create(): void
    {
        // Arrange
        $headers = $this->staff(PermissionName::ManageBillboards);

        // Act
        $response = $this->withHeaders($headers)->post('/api/v1/billboards', [
            'title' => 'بلا صورة',
        ], ['Accept' => 'application/json']);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('image');
    }

    /**
     * An SVG is an HTML document, and one served from our own origin is stored XSS — the same
     * rule the design and receipt uploads carry.
     */
    public function test_an_svg_banner_is_refused(): void
    {
        // Arrange
        $headers = $this->staff(PermissionName::ManageBillboards);
        $svg = UploadedFile::fake()->createWithContent('b.svg', '<svg><script>alert(1)</script></svg>');

        // Act
        $response = $this->withHeaders($headers)->post('/api/v1/billboards', [
            'image' => $svg,
        ], ['Accept' => 'application/json']);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('image');
    }

    // ─────────────────────── who may manage them ───────────────────────

    public function test_managing_the_billboard_needs_its_permission(): void
    {
        // Arrange — a signed-in employee holding nothing.
        $headers = $this->staff();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/billboards');

        // Assert
        $response->assertForbidden();
    }

    public function test_a_customer_cannot_reach_the_management_endpoints(): void
    {
        // Arrange
        $headers = $this->customerBearer();

        // Act
        $response = $this->withHeaders($headers)->getJson('/api/v1/billboards');

        // Assert
        $response->assertUnauthorized();
    }
}
