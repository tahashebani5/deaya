<?php

declare(strict_types=1);

namespace Tests\Feature\Investors;

use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Models\User;
use App\Domain\Investor\Models\Investor;
use App\Domain\Investor\Models\InvestorDeal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The two history endpoints the investor screens open — `/investors/{investor}/logs` and
 * `/investor-deals/{deal}/logs`.
 *
 * Both controllers already had a `logs()` method, and both called a helper that does not exist.
 * Neither route was registered, so the mistake could not be reached and nothing failed. Every
 * test here therefore goes through HTTP and reads the body: asserting that a route resolves
 * would have passed against the fatal underneath it.
 *
 * The gate is `logs.view`, deliberately not `investors.view`. A history names every colleague
 * who touched the money, which is a different decision from reading the deal.
 *
 * Arrange - Act - Assert throughout.
 */
class InvestorAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are defined by the code, so the guards under test only exist once these
        // rows do.
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }
    }

    /**
     * A user holding exactly the permissions named.
     *
     * @return array<string, string>
     */
    private function auth(PermissionName ...$permissions): array
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn (PermissionName $p) => $p->value, $permissions));

        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    /** May read history, and nothing else. */
    private function auditor(): array
    {
        return $this->auth(PermissionName::ViewActivityLogs);
    }

    // ─────────────────────────── happy paths ───────────────────────────

    public function test_an_investors_history_lists_its_changes_newest_first(): void
    {
        // Arrange
        $investor = Investor::factory()->create(['name' => 'مستثمر أول']);
        $investor->update(['name' => 'مستثمر ثانٍ']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/investors/{$investor->id}/logs");

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'تم بنجاح')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'updated')
            ->assertJsonPath('data.1.event', 'created')
            ->assertJsonPath('data.0.subject_type', 'investor')
            ->assertJsonPath('data.0.subject_type_label', 'مستثمر')
            ->assertJsonPath('data.0.subject_id', $investor->id)
            ->assertJsonPath('data.0.changes.old.name', 'مستثمر أول')
            ->assertJsonPath('data.0.changes.attributes.name', 'مستثمر ثانٍ');
    }

    public function test_a_deals_history_lists_its_changes_newest_first(): void
    {
        // Arrange
        $deal = InvestorDeal::factory()->create(['notes' => 'ملاحظة أولى']);
        $deal->update(['notes' => 'ملاحظة ثانية']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/investor-deals/{$deal->id}/logs");

        // Assert
        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'updated')
            ->assertJsonPath('data.1.event', 'created')
            ->assertJsonPath('data.0.subject_type', 'investor_deal')
            ->assertJsonPath('data.0.subject_type_label', 'صفقة استثمار')
            ->assertJsonPath('data.0.subject_id', $deal->id)
            ->assertJsonPath('data.0.changes.attributes.notes', 'ملاحظة ثانية');
    }

    public function test_the_trail_carries_the_pagination_and_the_event_counts_every_other_history_carries(): void
    {
        // Arrange — the chip counts live in `meta` because a client counting the rows in front
        // of it is counting one page, and «إنشاء (1)» has to mean the whole trail.
        $investor = Investor::factory()->create();
        $investor->update(['name' => 'اسم آخر']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/investors/{$investor->id}/logs");

        // Assert
        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.event_counts.created', 1)
            ->assertJsonPath('meta.event_counts.updated', 1);
    }

    public function test_a_filter_narrows_an_investors_trail_the_same_way_it_narrows_every_other(): void
    {
        // Arrange
        $investor = Investor::factory()->create();
        $investor->update(['name' => 'اسم آخر']);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/investors/{$investor->id}/logs?event=created");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'created');
    }

    public function test_one_investors_trail_never_carries_anothers(): void
    {
        // Arrange
        $investor = Investor::factory()->create();
        $other = Investor::factory()->create();
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson("/api/v1/investors/{$investor->id}/logs");

        // Assert
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject_id', $investor->id);
        $this->assertNotSame($other->id, $response->json('data.0.subject_id'));
    }

    // ─────────────────────────── access ───────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function historyEndpoints(): array
    {
        return [
            'an investor' => ['investor'],
            'a deal' => ['deal'],
        ];
    }

    /** The URL for one of the endpoints above, with a real record behind it. */
    private function urlFor(string $record): string
    {
        return $record === 'investor'
            ? '/api/v1/investors/'.Investor::factory()->create()->id.'/logs'
            : '/api/v1/investor-deals/'.InvestorDeal::factory()->create()->id.'/logs';
    }

    #[DataProvider('historyEndpoints')]
    public function test_the_history_refuses_an_unauthenticated_caller(string $record): void
    {
        // Arrange
        $url = $this->urlFor($record);

        // Act
        $response = $this->getJson($url);

        // Assert
        $response->assertStatus(401)->assertJsonPath('message', 'غير مصرح لك بالدخول');
    }

    #[DataProvider('historyEndpoints')]
    public function test_someone_who_may_read_investors_may_not_read_their_history(string $record): void
    {
        // Arrange — holds every permission in the catalogue *except* `logs.view`, including
        // `investors.view` and `investors.manage`. Being allowed to run the deals is not being
        // allowed to audit who ran them.
        $url = $this->urlFor($record);
        $everythingElse = array_filter(
            PermissionName::cases(),
            fn (PermissionName $p) => $p !== PermissionName::ViewActivityLogs,
        );
        $headers = $this->auth(...$everythingElse);

        // Act
        $response = $this->withHeaders($headers)->getJson($url);

        // Assert
        $response->assertStatus(403)->assertJsonPath('message', 'ليس لديك صلاحية لتنفيذ هذا الإجراء');
    }

    #[DataProvider('historyEndpoints')]
    public function test_the_history_needs_nothing_but_the_logs_permission(string $record): void
    {
        // Arrange — the mirror of the test above: `logs.view` alone, without `investors.view`.
        $url = $this->urlFor($record);
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson($url);

        // Assert
        $response->assertOk()->assertJsonPath('status', true);
    }

    // ─────────────────────────── not found ───────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function missingRecords(): array
    {
        return [
            'an investor' => ['/api/v1/investors/999999/logs'],
            'a deal' => ['/api/v1/investor-deals/999999/logs'],
        ];
    }

    #[DataProvider('missingRecords')]
    public function test_asking_for_the_history_of_a_record_that_does_not_exist_is_a_404(string $url): void
    {
        // Arrange
        $headers = $this->auditor();

        // Act
        $response = $this->withHeaders($headers)->getJson($url);

        // Assert
        $response->assertNotFound()->assertJsonPath('message', 'العنصر المطلوب غير موجود');
    }
}
