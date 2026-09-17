<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Client;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The customer's own design library, reached from the app.
 *
 * **Not one of these paths carries a customer id, and that is the security.** The staff routes
 * next door are `customers/{customer}/designs/{design}` with `scoped()` doing the confinement;
 * here the only id in the path is the design's, so `test_another_customers_design_is_invisible`
 * and its siblings are what stand in for `scoped()`. If the controller ever resolves a design
 * through `CustomerDesign::find()` instead of through the signed-in customer's own relation,
 * those are the tests that go red.
 *
 * Arrange - Act - Assert throughout.
 */
class CustomerDesignLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function customer(string $phone = '0911111111'): Customer
    {
        return Customer::factory()->registered()->create(['phone' => $phone]);
    }

    /**
     * @return array<string, string>
     */
    private function bearerFor(Customer $customer): array
    {
        return ['Authorization' => 'Bearer '.$customer->createToken('test-device')->plainTextToken];
    }

    private function pdf(string $name = 'artwork.pdf', int $kilobytes = 20): UploadedFile
    {
        // A real %PDF header — validation reads the magic bytes, never the filename.
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n".str_repeat('a', $kilobytes * 1024),
        );
    }

    // ─────────────────────────── the list ───────────────────────────

    public function test_the_list_shows_only_my_own_designs(): void
    {
        // Arrange
        $me = $this->customer();
        $someoneElse = $this->customer('0922222222');

        CustomerDesign::factory()->count(2)->create(['customer_id' => $me->id]);
        $theirs = CustomerDesign::factory()->create(['customer_id' => $someoneElse->id]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/designs');

        // Assert
        $response->assertOk()->assertJsonCount(2, 'data');

        $ids = array_column((array) $response->json('data'), 'id');
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_the_list_is_newest_first(): void
    {
        // Arrange
        $me = $this->customer();
        $older = CustomerDesign::factory()->create(['customer_id' => $me->id]);
        $newer = CustomerDesign::factory()->create(['customer_id' => $me->id]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/designs');

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_an_empty_library_is_an_empty_list_not_an_error(): void
    {
        // Act
        $response = $this->withHeaders($this->bearerFor($this->customer()))
            ->getJson('/api/v1/client/designs');

        // Assert
        $response->assertOk()->assertJsonPath('status', true)->assertJsonCount(0, 'data');
    }

    /**
     * `notes` is where staff write to each other about a design — «العميل ما عجبه اللون». It is
     * the same category of text as a customer comment, and it never leaves for the app.
     */
    public function test_the_library_never_exposes_the_staff_notes(): void
    {
        // Arrange
        $me = $this->customer();
        CustomerDesign::factory()->create([
            'customer_id' => $me->id,
            'notes' => 'ملاحظة داخلية للموظفين',
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->getJson('/api/v1/client/designs');

        // Assert
        $response->assertOk();
        $this->assertArrayNotHasKey('notes', (array) $response->json('data.0'));
        $response->assertDontSee('ملاحظة داخلية للموظفين');
    }

    // ─────────────────────────── uploading ───────────────────────────

    public function test_a_customer_uploads_a_design_to_their_own_library(): void
    {
        // Arrange
        $me = $this->customer();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->post(
            '/api/v1/client/designs',
            ['file' => $this->pdf(), 'label' => 'شعار المتجر'],
            ['Accept' => 'application/json'],
        );

        // Assert
        $response->assertCreated()->assertJsonPath('data.label', 'شعار المتجر');

        $this->assertDatabaseHas('customer_designs', [
            'customer_id' => $me->id,
            'label' => 'شعار المتجر',
        ]);
    }

    /**
     * A dropped connection leaves the app unable to know whether the upload landed, so a retry
     * has to be free — the staff endpoint makes the same promise.
     */
    public function test_uploading_the_same_file_twice_returns_the_first_design(): void
    {
        // Arrange
        $me = $this->customer();
        $headers = $this->bearerFor($me);
        $first = $this->withHeaders($headers)->post(
            '/api/v1/client/designs',
            ['file' => $this->pdf()],
            ['Accept' => 'application/json'],
        );

        // Act
        $second = $this->withHeaders($headers)->post(
            '/api/v1/client/designs',
            ['file' => $this->pdf()],
            ['Accept' => 'application/json'],
        );

        // Assert
        $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, CustomerDesign::query()->where('customer_id', $me->id)->count());
    }

    /**
     * An SVG is an HTML document, and one served from our own origin is stored XSS. The client
     * endpoint shares the staff endpoint's FormRequest precisely so this list cannot drift.
     */
    public function test_an_svg_is_refused(): void
    {
        // Arrange
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        // Act
        $response = $this->withHeaders($this->bearerFor($this->customer()))->post(
            '/api/v1/client/designs',
            ['file' => $svg],
            ['Accept' => 'application/json'],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_the_file_is_required(): void
    {
        // Act
        $response = $this->withHeaders($this->bearerFor($this->customer()))->post(
            '/api/v1/client/designs',
            ['label' => 'بلا ملف'],
            ['Accept' => 'application/json'],
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    /**
     * The customer names their own artwork; what staff wrote about it is not a field they can
     * reach, in either direction.
     */
    public function test_a_customer_cannot_write_the_staff_notes_when_uploading(): void
    {
        // Arrange
        $me = $this->customer();

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->post(
            '/api/v1/client/designs',
            ['file' => $this->pdf(), 'label' => 'شعار', 'notes' => 'محاولة كتابة ملاحظة'],
            ['Accept' => 'application/json'],
        );

        // Assert
        $response->assertCreated();
        $this->assertDatabaseMissing('customer_designs', ['notes' => 'محاولة كتابة ملاحظة']);
    }

    // ─────────────────────────── renaming ───────────────────────────

    public function test_a_customer_renames_their_own_design(): void
    {
        // Arrange
        $me = $this->customer();
        $design = CustomerDesign::factory()->create(['customer_id' => $me->id, 'label' => 'قديم']);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->patchJson("/api/v1/client/designs/{$design->id}", ['label' => 'جديد']);

        // Assert
        $response->assertOk()->assertJsonPath('data.label', 'جديد');
        $this->assertDatabaseHas('customer_designs', ['id' => $design->id, 'label' => 'جديد']);
    }

    public function test_renaming_cannot_touch_the_staff_notes(): void
    {
        // Arrange
        $me = $this->customer();
        $design = CustomerDesign::factory()->create([
            'customer_id' => $me->id,
            'notes' => 'ملاحظة الموظفين',
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))->patchJson(
            "/api/v1/client/designs/{$design->id}",
            ['label' => 'جديد', 'notes' => 'محاولة'],
        );

        // Assert
        $response->assertOk();
        $this->assertDatabaseHas('customer_designs', [
            'id' => $design->id,
            'notes' => 'ملاحظة الموظفين',
        ]);
    }

    // ─────────────────────────── removing ───────────────────────────

    /**
     * Removing hides the row and keeps the object. An order placed last year points at this
     * design, and must still be able to show what was printed.
     */
    public function test_removing_a_design_hides_the_row_and_keeps_the_file(): void
    {
        // Arrange
        $me = $this->customer();
        $design = CustomerDesign::factory()->create(['customer_id' => $me->id]);
        Storage::disk($design->disk)->put($design->path, 'the bytes');

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->deleteJson("/api/v1/client/designs/{$design->id}");

        // Assert
        $response->assertOk();
        $this->assertSoftDeleted('customer_designs', ['id' => $design->id]);
        Storage::disk($design->disk)->assertExists($design->path);
    }

    // ─────────────────────────── ownership ───────────────────────────

    /**
     * **The test standing in for `scoped()`.** With no customer segment in the path, nothing but
     * the controller's own lookup keeps one customer out of another's library.
     */
    public function test_another_customers_design_is_invisible_to_read(): void
    {
        // Arrange
        $me = $this->customer();
        $theirs = CustomerDesign::factory()->create([
            'customer_id' => $this->customer('0922222222')->id,
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->patchJson("/api/v1/client/designs/{$theirs->id}", ['label' => 'سرقة']);

        // Assert
        $response->assertNotFound();
        $this->assertDatabaseHas('customer_designs', ['id' => $theirs->id, 'label' => $theirs->label]);
    }

    public function test_another_customers_design_cannot_be_deleted(): void
    {
        // Arrange
        $me = $this->customer();
        $theirs = CustomerDesign::factory()->create([
            'customer_id' => $this->customer('0922222222')->id,
        ]);

        // Act
        $response = $this->withHeaders($this->bearerFor($me))
            ->deleteJson("/api/v1/client/designs/{$theirs->id}");

        // Assert
        $response->assertNotFound();
        $this->assertDatabaseHas('customer_designs', ['id' => $theirs->id, 'deleted_at' => null]);
    }

    // ─────────────────────────── the guard ───────────────────────────

    public function test_the_library_needs_a_customer_token(): void
    {
        // Act
        $response = $this->getJson('/api/v1/client/designs');

        // Assert
        $response->assertUnauthorized();
    }

    public function test_a_staff_token_cannot_reach_the_customer_library(): void
    {
        // Arrange
        $staff = ['Authorization' => 'Bearer '.User::factory()->create()->createToken('s')->plainTextToken];

        // Act
        $response = $this->withHeaders($staff)->getJson('/api/v1/client/designs');

        // Assert
        $response->assertUnauthorized();
    }
}
