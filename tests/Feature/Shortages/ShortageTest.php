<?php

declare(strict_types=1);

namespace Tests\Feature\Shortages;

use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Order\Enums\PaymentMethod;
use App\Domain\Shortage\Enums\ShortageSource;
use App\Domain\Shortage\Enums\ShortageStatus;
use App\Domain\Shortage\Enums\SupplyKind;
use App\Domain\Shortage\Models\Shortage;
use App\Domain\Shortage\Models\ShortageSupply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The shortages section itself — writing one down, chasing it, and closing it with money.
 *
 * The reconciliation with an order has its own file; this one covers the endpoints and the
 * arithmetic nobody should be able to influence from outside.
 *
 * Arrange - Act - Assert throughout.
 */
class ShortageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * @return array<string, string>
     */
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    private function clerk(): array
    {
        return $this->auth(
            PermissionName::ViewShortages,
            PermissionName::ManageShortages,
            PermissionName::AssignShortages,
            PermissionName::RecordShortageSupplies,
            PermissionName::ReverseShortageSupplies,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'أكياس شحن',
            'unit' => PricingUnit::Kilogram->value,
            'required_quantity' => '30',
        ], $overrides);
    }

    // ── creating ────────────────────────────────────────────────────────────────────────

    public function test_a_shortage_is_written_down_by_hand_and_starts_new(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->postJson('/api/v1/shortages', $this->payload(), $headers);

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.source', ShortageSource::Manual->value)
            ->assertJsonPath('data.status', ShortageStatus::New->value)
            ->assertJsonPath('data.required_quantity', '30.000')
            ->assertJsonPath('data.supplied_quantity', '0.000')
            ->assertJsonPath('data.remaining_quantity', '30.000')
            ->assertJsonPath('data.total_paid', '0.00')
            ->assertJsonPath('data.is_editable', true);

        // The code carries a letter, unlike an order's — «نقص ٤ على طلبية ٤» otherwise.
        $this->assertMatchesRegularExpression('/^N\d+$/', $response->json('data.code'));
    }

    /**
     * The decision from §٢٫١: what is written down by hand is often not in the catalogue.
     */
    public function test_a_manual_shortage_needs_no_product(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->postJson('/api/v1/shortages', $this->payload(['name' => 'شريط لاصق']), $headers);

        // Assert
        $response->assertCreated()->assertJsonPath('data.product_id', null);
    }

    public function test_a_shortage_of_nothing_is_refused(): void
    {
        // Arrange
        $headers = $this->clerk();

        // Act
        $response = $this->postJson('/api/v1/shortages', $this->payload(['required_quantity' => '0']), $headers);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('required_quantity');
    }

    public function test_writing_one_down_needs_the_manage_grant(): void
    {
        // Arrange
        $headers = $this->auth(PermissionName::ViewShortages);

        // Act
        $response = $this->postJson('/api/v1/shortages', $this->payload(), $headers);

        // Assert
        $response->assertForbidden();
    }

    // ── the chase ───────────────────────────────────────────────────────────────────────

    public function test_a_shortage_moves_along_the_chase(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create();

        // Act
        $response = $this->patchJson(
            "/api/v1/shortages/{$shortage->getKey()}/status",
            ['status' => ShortageStatus::Searching->value],
            $headers,
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.status', ShortageStatus::Searching->value);
    }

    /**
     * The rule the whole feature turns on, asserted at the endpoint as well as on the enum.
     */
    public function test_completion_cannot_be_chosen(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create();

        // Act
        $response = $this->patchJson(
            "/api/v1/shortages/{$shortage->getKey()}/status",
            ['status' => ShortageStatus::Completed->value],
            $headers,
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame(ShortageStatus::New, $shortage->refresh()->status);
    }

    public function test_assigning_a_new_shortage_starts_the_chase(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create();
        $employee = User::factory()->create();

        // Act
        $response = $this->patchJson(
            "/api/v1/shortages/{$shortage->getKey()}/assignee",
            ['assigned_to_user_id' => $employee->getKey()],
            $headers,
        );

        // Assert — «لم تبدأ متابعته بعد» stopped being true the moment it landed in a queue.
        $response->assertOk()
            ->assertJsonPath('data.assigned_to_user_id', $employee->getKey())
            ->assertJsonPath('data.status', ShortageStatus::Searching->value);
    }

    public function test_unassigning_leaves_the_chase_where_it_is(): void
    {
        // Arrange
        $headers = $this->clerk();
        $employee = User::factory()->create();
        $shortage = Shortage::factory()->assignedTo($employee->getKey())->create();

        // Act
        $response = $this->patchJson(
            "/api/v1/shortages/{$shortage->getKey()}/assignee",
            ['assigned_to_user_id' => null],
            $headers,
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.assigned_to_user_id', null)
            ->assertJsonPath('data.status', ShortageStatus::Searching->value);
    }

    /**
     * `present` rather than `required`: an omitted field is a bug, a null is a decision.
     */
    public function test_an_omitted_assignee_field_is_refused(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create();

        // Act
        $response = $this->patchJson("/api/v1/shortages/{$shortage->getKey()}/assignee", [], $headers);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('assigned_to_user_id');
    }

    public function test_assigning_needs_its_own_grant(): void
    {
        // Arrange — a clerk who may write shortages down but not route other people's work.
        $headers = $this->auth(PermissionName::ViewShortages, PermissionName::ManageShortages);
        $shortage = Shortage::factory()->create();
        $employee = User::factory()->create();

        // Act
        $response = $this->patchJson(
            "/api/v1/shortages/{$shortage->getKey()}/assignee",
            ['assigned_to_user_id' => $employee->getKey()],
            $headers,
        );

        // Assert
        $response->assertForbidden();
    }

    // ── supplying ───────────────────────────────────────────────────────────────────────

    /**
     * §٥, the worked example: thirty kilos, twenty then ten.
     */
    public function test_partial_supply_leaves_the_shortage_open_until_the_last_of_it(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '30.000']);

        // Act — the first go.
        $first = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '20',
            'amount' => '500',
            'method' => PaymentMethod::Cash->value,
        ], $headers);

        // Assert
        $first->assertCreated();

        $shortage->refresh();
        $this->assertSame('20.000', (string) $shortage->supplied_quantity);
        $this->assertSame('10.000', $shortage->remainingQuantity());
        $this->assertSame('500.00', (string) $shortage->total_paid);
        $this->assertSame(ShortageStatus::New, $shortage->status, 'still open');

        // Act — the second.
        $second = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '10',
            'amount' => '260',
            'method' => PaymentMethod::BankTransfer->value,
        ], $headers);

        // Assert — «المطلوب ٣٠ · تم توفيره ٣٠ · المتبقي ٠ · الإجمالي ٧٦٠ · مكتمل».
        $second->assertCreated();

        $shortage->refresh();
        $this->assertSame('30.000', (string) $shortage->supplied_quantity);
        $this->assertSame('0.000', $shortage->remainingQuantity());
        $this->assertSame('760.00', (string) $shortage->total_paid);
        $this->assertSame(ShortageStatus::Completed, $shortage->status);
    }

    public function test_a_supply_keeps_the_paper_it_was_bought_with(): void
    {
        // Arrange — الواصل on a شراء is optional, and this is the entry that has one.
        Storage::fake('local');
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '30.000']);

        // Act
        $response = $this->post(
            "/api/v1/shortages/{$shortage->getKey()}/supplies",
            [
                'quantity' => '20',
                'amount' => '500',
                'method' => PaymentMethod::BankTransfer->value,
                'receipt' => UploadedFile::fake()->create('waseel.pdf', 120, 'application/pdf'),
            ],
            $headers + ['Accept' => 'application/json'],
        );

        // Assert — the same three keys a payment's receipt publishes, because it is the same
        // question asked about the same kind of paper.
        $response->assertCreated()
            ->assertJsonPath('data.has_receipt', true)
            ->assertJsonPath('data.receipt_is_image', false)
            ->assertJsonPath('data.receipt_filename', 'waseel.pdf');

        $supply = ShortageSupply::query()->firstOrFail();
        Storage::disk('local')->assertExists((string) $supply->receipt_path);
        $this->assertStringEndsWith('.pdf', (string) $supply->receipt_path);
        // Generated, never the client's: nobody chooses a path, and two shops sending
        // «waseel.pdf» must not collide.
        $this->assertStringNotContainsString('waseel', (string) $supply->receipt_path);
    }

    public function test_a_supply_without_paper_is_recorded_all_the_same(): void
    {
        // Arrange — سَكّ اشتُري من المحل المجاور بلا ورقة، وهو الحال الغالب.
        Storage::fake('local');
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '30.000']);

        // Act
        $response = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '20',
            'amount' => '500',
            'method' => PaymentMethod::BankTransfer->value,
        ], $headers);

        // Assert — **not** the order's rule: a transfer to a customer is proved by the paper they
        // send us, while refusing this entry for want of a document would push the purchase back
        // onto paper, which is what the feature exists to end.
        $response->assertCreated()
            ->assertJsonPath('data.has_receipt', false)
            ->assertJsonPath('data.receipt_url', null);
    }

    public function test_a_supply_larger_than_what_is_left_is_refused(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '30.000']);

        // Act
        $response = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '31',
            'amount' => '800',
            'method' => PaymentMethod::Cash->value,
        ], $headers);

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('quantity');
        $this->assertSame('0.000', (string) $shortage->refresh()->supplied_quantity);
    }

    /**
     * «الكمية + القيمة + طريقة الدفع» — all three, every time.
     */
    public function test_a_supply_must_say_what_it_cost_and_how_it_was_paid(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create();

        // Act
        $response = $this->postJson(
            "/api/v1/shortages/{$shortage->getKey()}/supplies",
            ['quantity' => '10'],
            $headers,
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors(['amount', 'method']);
    }

    public function test_nothing_more_may_be_recorded_against_a_completed_shortage(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '10.000']);

        $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '10',
            'amount' => '200',
            'method' => PaymentMethod::Cash->value,
        ], $headers)->assertCreated();

        // Act
        $response = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '1',
            'amount' => '20',
            'method' => PaymentMethod::Cash->value,
        ], $headers);

        // Assert
        $response->assertStatus(422);
        $this->assertSame('200.00', (string) $shortage->refresh()->total_paid);
    }

    /**
     * «غير متوفر» is not the end of the road — §٦.
     */
    public function test_a_supply_reopens_an_abandoned_shortage(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create([
            'required_quantity' => '30.000',
            'status' => ShortageStatus::Unavailable,
        ]);

        // Act
        $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '10',
            'amount' => '250',
            'method' => PaymentMethod::Cash->value,
        ], $headers)->assertCreated();

        // Assert
        $this->assertSame(ShortageStatus::Searching, $shortage->refresh()->status);
    }

    public function test_recording_a_supply_needs_its_own_grant(): void
    {
        // Arrange — may chase a shortage, may not spend money on it.
        $headers = $this->auth(PermissionName::ViewShortages, PermissionName::ManageShortages);
        $shortage = Shortage::factory()->create();

        // Act
        $response = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '10',
            'amount' => '250',
            'method' => PaymentMethod::Cash->value,
        ], $headers);

        // Assert
        $response->assertForbidden();
    }

    // ── reversing ───────────────────────────────────────────────────────────────────────

    public function test_a_reversal_restates_the_totals_and_reopens_the_shortage(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '10.000']);

        $supplyId = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '10',
            'amount' => '250',
            'method' => PaymentMethod::Cash->value,
        ], $headers)->assertCreated()->json('data.id');

        $this->assertSame(ShortageStatus::Completed, $shortage->refresh()->status);

        // Act
        $response = $this->postJson(
            "/api/v1/shortages/{$shortage->getKey()}/supplies/{$supplyId}/reversal",
            ['reason' => 'أُدخلت مرتين'],
            $headers,
        );

        // Assert — nothing was deleted; the pair nets to zero and the chase resumes.
        $response->assertCreated();

        $shortage->refresh();
        $this->assertSame('0.000', (string) $shortage->supplied_quantity);
        $this->assertSame('0.00', (string) $shortage->total_paid);
        $this->assertSame(ShortageStatus::Searching, $shortage->status);
        $this->assertSame(2, $shortage->supplies()->count(), 'the mistake is kept beside its correction');
    }

    public function test_a_supply_is_reversed_only_once(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '10.000']);

        $supplyId = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '5',
            'amount' => '100',
            'method' => PaymentMethod::Cash->value,
        ], $headers)->json('data.id');

        $this->postJson(
            "/api/v1/shortages/{$shortage->getKey()}/supplies/{$supplyId}/reversal",
            ['reason' => 'خطأ'],
            $headers,
        )->assertCreated();

        // Act
        $response = $this->postJson(
            "/api/v1/shortages/{$shortage->getKey()}/supplies/{$supplyId}/reversal",
            ['reason' => 'مرة أخرى'],
            $headers,
        );

        // Assert
        $response->assertStatus(422);
        $this->assertSame(2, $shortage->supplies()->count());
    }

    public function test_a_reversal_demands_a_reason(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '10.000']);

        $supplyId = $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '5',
            'amount' => '100',
            'method' => PaymentMethod::Cash->value,
        ], $headers)->json('data.id');

        // Act
        $response = $this->postJson(
            "/api/v1/shortages/{$shortage->getKey()}/supplies/{$supplyId}/reversal",
            [],
            $headers,
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    /**
     * A quantity that arrived through the order screen is the sync's record, not an entry
     * somebody made — correcting it belongs on the order.
     */
    public function test_an_arrival_from_the_order_cannot_be_reversed(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '30.000']);
        $arrival = $shortage->supplies()->make();
        $arrival->forceFill([
            'kind' => SupplyKind::ResolvedExternally,
            'quantity' => '10.000',
            'occurred_on' => now()->toDateString(),
        ])->save();

        // Act
        $response = $this->postJson(
            "/api/v1/shortages/{$shortage->getKey()}/supplies/{$arrival->getKey()}/reversal",
            ['reason' => 'خطأ'],
            $headers,
        );

        // Assert
        $response->assertStatus(422);
    }

    /**
     * `scopeBindings()` on the route — another shortage's entry is a 404 by construction.
     */
    public function test_a_supply_on_another_shortage_is_not_found(): void
    {
        // Arrange
        $headers = $this->clerk();
        $mine = Shortage::factory()->create();
        $theirs = Shortage::factory()->create();

        $supplyId = $this->postJson("/api/v1/shortages/{$theirs->getKey()}/supplies", [
            'quantity' => '5',
            'amount' => '100',
            'method' => PaymentMethod::Cash->value,
        ], $headers)->json('data.id');

        // Act
        $response = $this->postJson(
            "/api/v1/shortages/{$mine->getKey()}/supplies/{$supplyId}/reversal",
            ['reason' => 'خطأ'],
            $headers,
        );

        // Assert
        $response->assertNotFound();
    }

    // ── editing ─────────────────────────────────────────────────────────────────────────

    /**
     * A requirement cut below what has already been bought would close the shortage by making
     * its own history impossible.
     */
    public function test_the_requirement_cannot_be_cut_below_what_was_supplied(): void
    {
        // Arrange
        $headers = $this->clerk();
        $shortage = Shortage::factory()->create(['required_quantity' => '30.000']);

        $this->postJson("/api/v1/shortages/{$shortage->getKey()}/supplies", [
            'quantity' => '20',
            'amount' => '500',
            'method' => PaymentMethod::Cash->value,
        ], $headers)->assertCreated();

        // Act
        $response = $this->putJson(
            "/api/v1/shortages/{$shortage->getKey()}",
            $this->payload(['required_quantity' => '10']),
            $headers,
        );

        // Assert
        $response->assertStatus(422);
        $this->assertSame('30.000', (string) $shortage->refresh()->required_quantity);
    }

    // ── the list and the board ──────────────────────────────────────────────────────────

    public function test_the_board_counts_every_status_including_the_empty_ones(): void
    {
        // Arrange
        $headers = $this->clerk();
        Shortage::factory()->count(2)->create();
        Shortage::factory()->create(['status' => ShortageStatus::Unavailable]);

        // Act
        $response = $this->getJson('/api/v1/shortages/summary', $headers);

        // Assert — a missing key would leave the caller choosing between a blank and a zero.
        $response->assertOk()
            ->assertJsonPath('data.counts.new', 2)
            ->assertJsonPath('data.counts.searching', 0)
            ->assertJsonPath('data.counts.unavailable', 1)
            ->assertJsonPath('data.counts.completed', 0)
            // Read rather than summed on the client — see the controller.
            ->assertJsonPath('data.total', 3);
    }

    /**
     * The chip row says what *else* there is, so it ignores the status already chosen — and
     * keeps every other filter, or «جديد ١٢» under a product filter would mean something else.
     */
    public function test_the_board_ignores_the_status_filter_and_keeps_the_others(): void
    {
        // Arrange
        $headers = $this->clerk();
        $employee = User::factory()->create();

        Shortage::factory()->assignedTo($employee->getKey())->create();
        Shortage::factory()->create(['status' => ShortageStatus::Unavailable]);

        // Act
        $response = $this->getJson(
            '/api/v1/shortages/summary?status=searching&assigned_to='.$employee->getKey(),
            $headers,
        );

        // Assert
        $response->assertOk()
            ->assertJsonPath('data.counts.searching', 1)
            ->assertJsonPath('data.counts.unavailable', 0);
    }

    public function test_an_employee_reads_their_own_queue(): void
    {
        // Arrange
        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewShortages->value);
        $headers = ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];

        $mine = Shortage::factory()->assignedTo($user->getKey())->create();
        Shortage::factory()->create();

        // Act
        $response = $this->getJson('/api/v1/shortages?assigned_to=me', $headers);

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->getKey());
    }

    public function test_unassigned_work_is_its_own_queue(): void
    {
        // Arrange
        $headers = $this->clerk();
        $employee = User::factory()->create();

        $loose = Shortage::factory()->create();
        Shortage::factory()->assignedTo($employee->getKey())->create();

        // Act
        $response = $this->getJson('/api/v1/shortages?assigned_to=none', $headers);

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $loose->getKey());
    }

    public function test_reading_needs_the_view_grant(): void
    {
        // Arrange
        $headers = $this->auth();

        // Act
        $response = $this->getJson('/api/v1/shortages', $headers);

        // Assert
        $response->assertForbidden();
    }
}
