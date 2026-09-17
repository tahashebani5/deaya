<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerDesign;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A customer's artwork — the image or PDF printed on their bags.
 *
 * The rule this whole feature exists to keep: **a design's file is never erased.** An order
 * placed last year points at one, and the colleague tidying today's list has no idea which.
 *
 * Arrange - Act - Assert throughout.
 */
class CustomerDesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing here touches a real disk.
        Storage::fake('local');
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

    private function pdf(string $name = 'artwork.pdf', int $kilobytes = 200): UploadedFile
    {
        // A real %PDF header, because validation reads the magic bytes rather than the name.
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n".str_repeat('a', $kilobytes * 1024),
        );
    }

    // ─────────────────────────── uploading ───────────────────────────

    public function test_a_pdf_is_accepted_and_recorded_as_one(): void
    {
        // Arrange — the case the product-image layer cannot handle: its `image` rule refuses a
        // PDF outright.
        $headers = $this->auth();
        $customer = Customer::factory()->create();

        // Act
        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => $this->pdf(), 'label' => 'تصميم الكيس الكبير'],
            $headers,
        );

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.kind', 'pdf')
            ->assertJsonPath('data.label', 'تصميم الكيس الكبير')
            // A PDF has pages, not pixels.
            ->assertJsonPath('data.width_px', null);

        $design = CustomerDesign::query()->sole();
        $this->assertSame('application/pdf', $design->mime_type);
        Storage::disk('local')->assertExists($design->path);
    }

    public function test_an_image_is_accepted_and_measured(): void
    {
        // Arrange
        $headers = $this->auth();
        $customer = Customer::factory()->create();

        // Act
        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => UploadedFile::fake()->image('logo.png', 1200, 1600)],
            $headers,
        );

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.width_px', 1200)
            ->assertJsonPath('data.height_px', 1600)
            // Defaulted from the filename: the label is the only way staff tell two designs
            // apart, so a row must never arrive without one.
            ->assertJsonPath('data.label', 'logo');
    }

    public function test_the_type_is_read_from_the_bytes_not_from_the_name_the_client_chose(): void
    {
        // Arrange — a PDF sent under an image's name. The product uploader stores
        // `getClientMimeType()`, the client's own claim, which was harmless there only because
        // its `image` rule had already proved the content really was an image. That rule cannot
        // exist here — it is the one that rejects a PDF — so the claim would be the only record
        // of what the file is, and it is chosen by whoever uploaded it.
        $headers = $this->auth();
        $customer = Customer::factory()->create();

        // A *real* UploadedFile, not `UploadedFile::fake()`. The fake derives its mime type
        // from the filename it was given, so it cannot exercise this at all — it would report
        // `image/jpeg` for PDF bytes and the test would pass against nothing.
        $path = tempnam(sys_get_temp_dir(), 'design').'.jpg';
        file_put_contents($path, "%PDF-1.4\n".str_repeat('a', 1024));
        $file = new UploadedFile($path, 'logo.jpg', null, null, true);

        // Act
        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => $file],
            $headers,
        );

        // Assert — accepted, and filed as what it *is*. The name is ignored end to end: the
        // stored path takes the extension guessed from the bytes, so nothing on disk carries
        // the lie either.
        $response->assertCreated()->assertJsonPath('data.kind', 'pdf');

        $design = CustomerDesign::query()->sole();
        $this->assertSame('application/pdf', $design->mime_type);
        $this->assertStringEndsWith('.pdf', $design->path);
    }

    public function test_a_file_that_is_neither_an_image_nor_a_pdf_is_refused(): void
    {
        // Arrange
        $headers = $this->auth();
        $customer = Customer::factory()->create();

        // Act
        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => UploadedFile::fake()->createWithContent('design.svg', '<svg/>')],
            $headers,
        );

        // Assert — an SVG especially: it is an HTML document, and one served from our own
        // origin is stored XSS.
        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_sending_the_same_file_twice_does_not_store_it_twice(): void
    {
        // Arrange — a dropped connection leaves the caller unable to know whether the request
        // landed, so it has to retry. A retry must be free.
        $headers = $this->auth();
        $customer = Customer::factory()->create();
        $first = $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => $this->pdf()],
            $headers,
        );

        // Act
        $second = $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => $this->pdf()],
            $headers,
        );

        // Assert — 200, not 201: nothing was created, and the caller gets the row that exists.
        $first->assertCreated();
        $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, CustomerDesign::query()->count());
    }

    public function test_a_customer_cannot_hold_more_designs_than_the_limit(): void
    {
        // Arrange — the only real bound on storage, because files are never erased.
        config(['media.customer_designs.max_per_customer' => 2]);

        $headers = $this->auth();
        $customer = Customer::factory()->create();
        CustomerDesign::factory()->count(2)->create(['customer_id' => $customer->id]);

        // Act
        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => $this->pdf()],
            $headers,
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    // ─────────────────────────── reading ───────────────────────────

    public function test_the_list_is_newest_first_and_carries_a_usable_link(): void
    {
        // Arrange
        $headers = $this->auth();
        $customer = Customer::factory()->create();
        $older = CustomerDesign::factory()->create(['customer_id' => $customer->id]);
        $newer = CustomerDesign::factory()->pdf()->create(['customer_id' => $customer->id]);

        // Act
        $response = $this->getJson("/api/v1/customers/{$customer->id}/designs", $headers);

        // Assert — the design somebody wants is nearly always the one just uploaded.
        $response->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.0.kind', 'pdf');

        $this->assertNotEmpty($response->json('data.0.file_url'));
    }

    public function test_another_customers_design_is_not_reachable_through_this_one(): void
    {
        // Arrange — `scoped()` makes this a 404 by construction rather than by a check somebody
        // has to remember to write.
        $headers = $this->auth();
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        $design = CustomerDesign::factory()->create(['customer_id' => $theirs->id]);

        // Act
        $response = $this->deleteJson(
            "/api/v1/customers/{$mine->id}/designs/{$design->id}",
            [],
            $headers,
        );

        // Assert
        $response->assertNotFound();
        $this->assertSame(1, CustomerDesign::query()->count());
    }

    // ─────────────────────────── changing ───────────────────────────

    public function test_a_design_can_be_renamed(): void
    {
        // Arrange
        $headers = $this->auth();
        $customer = Customer::factory()->create();
        $design = CustomerDesign::factory()->create(['customer_id' => $customer->id]);

        // Act
        $response = $this->putJson(
            "/api/v1/customers/{$customer->id}/designs/{$design->id}",
            ['label' => 'الشعار الذهبي', 'notes' => 'يُطبع على الوجه الأمامي'],
            $headers,
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.label', 'الشعار الذهبي');
    }

    public function test_deleting_a_design_hides_it_but_keeps_the_file(): void
    {
        // Arrange — the rule the whole feature exists for. An order printed last year points at
        // this design, and whoever tidies the list today has no idea which ones an order used.
        $headers = $this->auth();
        $customer = Customer::factory()->create();
        $upload = $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => $this->pdf()],
            $headers,
        );
        $design = CustomerDesign::query()->sole();

        // Act
        $response = $this->deleteJson(
            "/api/v1/customers/{$customer->id}/designs/{$design->id}",
            [],
            $headers,
        );

        // Assert
        $upload->assertCreated();
        $response->assertOk();
        $this->assertSoftDeleted('customer_designs', ['id' => $design->id]);
        // The half that matters, and the opposite of what a product image does.
        Storage::disk('local')->assertExists($design->path);
        $this->getJson("/api/v1/customers/{$customer->id}/designs", $headers)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_removing_a_design_frees_its_file_to_be_uploaded_again(): void
    {
        // Arrange — the unique index is partial, so a removed design releases its claim.
        $headers = $this->auth();
        $customer = Customer::factory()->create();
        $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => $this->pdf()],
            $headers,
        );
        $design = CustomerDesign::query()->sole();
        $this->deleteJson("/api/v1/customers/{$customer->id}/designs/{$design->id}", [], $headers);

        // Act
        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => $this->pdf()],
            $headers,
        );

        // Assert — a new row, not the trashed one handed back.
        $response->assertCreated();
        $this->assertNotSame($design->id, $response->json('data.id'));
    }

    // ─────────────────────────── history ───────────────────────────

    public function test_the_customers_history_includes_designs_that_were_removed(): void
    {
        // Arrange — "who removed the logo we were printing?" is the question this history
        // exists to answer, and a removed design is the only kind anyone asks about. Without
        // `withTrashed` in `Customer::auditTrailSubjects()` it silently omits every one.
        $headers = $this->auth();
        $customer = Customer::factory()->create();
        $this->postJson(
            "/api/v1/customers/{$customer->id}/designs",
            ['file' => $this->pdf()],
            $headers,
        );
        $design = CustomerDesign::query()->sole();
        $this->deleteJson("/api/v1/customers/{$customer->id}/designs/{$design->id}", [], $headers);

        // Act
        $response = $this->getJson("/api/v1/customers/{$customer->id}/logs", $headers);

        // Assert
        $response->assertOk();
        $subjects = collect($response->json('data'))->pluck('subject_type')->unique();
        $this->assertContains('customer_design', $subjects);
    }

    // ─────────────────────────── who may ───────────────────────────

    public function test_reading_designs_needs_permission_to_see_the_customer(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        $stranger = User::factory()->create();
        $headers = [
            'Authorization' => 'Bearer '.$stranger->createToken('test')->plainTextToken,
        ];

        // Act
        $response = $this->getJson("/api/v1/customers/{$customer->id}/designs", $headers);

        // Assert
        $response->assertForbidden();
    }
}
