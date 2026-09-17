<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Comment\Models\Comment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\User;
use App\Domain\Vendor\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What staff write to each other about a supplier.
 *
 * The same feature `CustomerCommentTest` pins, on the second record to gain it — and the reason
 * the table was generalised at all. What is tested here is not the four verbs again but the
 * three things the move could have broken: that a supplier's notes are guarded by
 * `vendors.view` rather than by the customer's permission, that one moderator permission covers
 * both kinds, and that the two owners' notes cannot reach each other.
 *
 * Arrange - Act - Assert throughout.
 */
class VendorCommentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Somebody who may read suppliers, and nothing more.
     *
     * That is the whole grant a note-writer needs — the customer's rule, mirrored.
     */
    private function reader(): User
    {
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewVendors->value);

        return $user;
    }

    /** Somebody the business has put over other people's notes — on every kind of record. */
    private function moderator(): User
    {
        $user = $this->reader();
        $user->givePermissionTo(PermissionName::ModerateComments->value);

        return $user;
    }

    /** Authenticated, granted nothing at all. */
    private function outsider(): User
    {
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        return User::factory()->create();
    }

    private function admin(): User
    {
        $user = User::factory()->create();

        Role::findOrCreate(RoleName::Admin->value, 'web');
        $user->syncRoles([RoleName::Admin->value]);

        return $user;
    }

    /**
     * Drop the resolved user from the auth guards.
     *
     * The container is reused across requests inside one test, so once a guard has authenticated
     * somebody it keeps returning them — a second request carrying a different token would still
     * be treated as the first. Needed by the two tests below, which genuinely need two callers.
     */
    private function forgetAuth(): void
    {
        $this->app->get('auth')->forgetGuards();
    }

    /**
     * @return array<string, string>
     */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ─────────────────────────── writing one ───────────────────────────

    public function test_a_reader_of_suppliers_may_leave_a_note(): void
    {
        // Arrange — `vendors.view` only: writing a note is not managing the supplier.
        $user = $this->reader();
        $vendor = Vendor::factory()->create();

        // Act
        $response = $this->withHeaders($this->headers($user))->postJson(
            "/api/v1/vendors/{$vendor->id}/comments",
            ['body' => 'لا يسلّم قبل الظهر'],
        );

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.body', 'لا يسلّم قبل الظهر')
            ->assertJsonPath('data.commentable_type', 'vendor')
            ->assertJsonPath('data.commentable_id', $vendor->id)
            ->assertJsonPath('data.author.id', $user->id)
            ->assertJsonPath('data.edited_at', null)
            ->assertJsonPath('data.can_edit', true);

        $this->assertDatabaseHas('comments', [
            'commentable_type' => 'vendor',
            'commentable_id' => $vendor->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_the_author_is_the_signed_in_user_and_never_the_body(): void
    {
        // Arrange — a body trying to sign somebody else's name to the sentence.
        $user = $this->reader();
        $colleague = User::factory()->create();
        $vendor = Vendor::factory()->create();

        // Act
        $response = $this->withHeaders($this->headers($user))->postJson(
            "/api/v1/vendors/{$vendor->id}/comments",
            ['body' => 'يرفع السعر إن طلبنا أقل من طن', 'user_id' => $colleague->id],
        );

        // Assert — attribution nobody can set is attribution nobody can forge.
        $response->assertCreated()->assertJsonPath('data.author.id', $user->id);
    }

    public function test_somebody_who_may_not_read_suppliers_may_not_write_a_note(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();

        // Act
        $response = $this->withHeaders($this->headers($this->outsider()))->postJson(
            "/api/v1/vendors/{$vendor->id}/comments",
            ['body' => 'محاولة'],
        );

        // Assert — `customers.view` is not the key to this door either: the guard is the
        // supplier's own permission.
        $response->assertForbidden();
    }

    // ─────────────────────────── reading them ───────────────────────────

    public function test_the_list_is_this_suppliers_notes_newest_first(): void
    {
        // Arrange
        $user = $this->reader();
        $vendor = Vendor::factory()->create();
        $other = Vendor::factory()->create();

        Comment::factory()->on($vendor)->create(['body' => 'الأقدم']);
        Comment::factory()->on($vendor)->create(['body' => 'الأحدث']);
        Comment::factory()->on($other)->create(['body' => 'مورد آخر']);
        Comment::factory()->on(Customer::factory()->create())->create(['body' => 'ملاحظة عميل']);

        // Act
        $response = $this->withHeaders($this->headers($user))
            ->getJson("/api/v1/vendors/{$vendor->id}/comments");

        // Assert — the last thing said is the first thing shown, and nothing written about
        // anybody else leaks in. The customer's note is the one that would have, before the
        // morph pair narrowed the read.
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'الأحدث')
            ->assertJsonPath('data.1.body', 'الأقدم');
    }

    public function test_a_removed_note_is_absent_from_the_list(): void
    {
        // Arrange
        $user = $this->reader();
        $vendor = Vendor::factory()->create();
        Comment::factory()->on($vendor)->create()->delete();

        // Act
        $response = $this->withHeaders($this->headers($user))
            ->getJson("/api/v1/vendors/{$vendor->id}/comments");

        // Assert — gone from the list, still in the history.
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    // ─────────────────────────── changing one ───────────────────────────

    public function test_its_author_may_rewrite_it_and_the_edit_is_stamped(): void
    {
        // Arrange
        $user = $this->reader();
        $vendor = Vendor::factory()->create();
        $comment = Comment::factory()->on($vendor)->for($user, 'author')->create();

        // Act
        $response = $this->withHeaders($this->headers($user))->patchJson(
            "/api/v1/vendors/{$vendor->id}/comments/{$comment->id}",
            ['body' => 'صار يسلّم صباحاً'],
        );

        // Assert — a sentence that quietly becomes a different sentence is worse than no note.
        $response->assertOk()
            ->assertJsonPath('data.body', 'صار يسلّم صباحاً')
            ->assertJsonPath('data.edited_at', fn (?string $at) => $at !== null);
    }

    public function test_a_colleagues_note_is_refused(): void
    {
        // Arrange
        $user = $this->reader();
        $vendor = Vendor::factory()->create();
        $comment = Comment::factory()->on($vendor)->create(['body' => 'كلام زميل']);

        // Act
        $response = $this->withHeaders($this->headers($user))->patchJson(
            "/api/v1/vendors/{$vendor->id}/comments/{$comment->id}",
            ['body' => 'كلامي أنا'],
        );

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'body' => 'كلام زميل']);
    }

    public function test_one_moderator_permission_covers_a_suppliers_notes_too(): void
    {
        // Arrange — the decision this test exists for: `comments.moderate` is not per record.
        $moderator = $this->moderator();
        $vendor = Vendor::factory()->create();
        $comment = Comment::factory()->on($vendor)->create();

        // Act
        $response = $this->withHeaders($this->headers($moderator))->patchJson(
            "/api/v1/vendors/{$vendor->id}/comments/{$comment->id}",
            ['body' => 'تصحيح من مشرف'],
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.body', 'تصحيح من مشرف');
    }

    public function test_its_author_may_remove_it_softly(): void
    {
        // Arrange
        $user = $this->reader();
        $vendor = Vendor::factory()->create();
        $comment = Comment::factory()->on($vendor)->for($user, 'author')->create();

        // Act
        $response = $this->withHeaders($this->headers($user))
            ->deleteJson("/api/v1/vendors/{$vendor->id}/comments/{$comment->id}");

        // Assert — soft, which is what keeps «من حذف الملاحظة؟» answerable.
        $response->assertOk();
        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    // ─────────────────────────── the scoping ───────────────────────────

    public function test_a_note_belonging_to_another_supplier_is_not_found(): void
    {
        // Arrange
        $user = $this->reader();
        $vendor = Vendor::factory()->create();
        $elsewhere = Comment::factory()->on(Vendor::factory()->create())
            ->for($user, 'author')
            ->create();

        // Act
        $response = $this->withHeaders($this->headers($user))->patchJson(
            "/api/v1/vendors/{$vendor->id}/comments/{$elsewhere->id}",
            ['body' => 'من الباب الخطأ'],
        );

        // Assert — 404 by construction, not by a check somebody remembered to write. Note the
        // author *is* this reader, so nothing but the scoping refuses this.
        $response->assertNotFound();
    }

    public function test_a_customers_note_cannot_be_reached_through_a_supplier(): void
    {
        // Arrange — the failure the morph pair has to prevent: two owners, one id space.
        $user = $this->reader();
        $vendor = Vendor::factory()->create();
        $customersNote = Comment::factory()->on(Customer::factory()->create())
            ->for($user, 'author')
            ->create();

        // Act
        $response = $this->withHeaders($this->headers($user))
            ->deleteJson("/api/v1/vendors/{$vendor->id}/comments/{$customersNote->id}");

        // Assert
        $response->assertNotFound();
        $this->assertDatabaseHas('comments', ['id' => $customersNote->id, 'deleted_at' => null]);
    }

    // ─────────────────────────── its history ───────────────────────────

    public function test_a_notes_history_is_behind_the_logs_permission(): void
    {
        // Arrange — somebody who may read suppliers, and an administrator.
        $reader = $this->reader();
        $vendor = Vendor::factory()->create();
        $comment = Comment::factory()->on($vendor)->create();
        $readerHeaders = $this->headers($reader);
        $adminHeaders = $this->headers($this->admin());

        // Act
        $this->forgetAuth();
        $refused = $this->withHeaders($readerHeaders)
            ->getJson("/api/v1/vendors/{$vendor->id}/comments/{$comment->id}/logs");

        $this->forgetAuth();
        $allowed = $this->withHeaders($adminHeaders)
            ->getJson("/api/v1/vendors/{$vendor->id}/comments/{$comment->id}/logs");

        // Assert — reading a history is a different decision from reading the record, and the
        // project rule holds here too: every model has its own `/logs`.
        $refused->assertForbidden();
        $allowed->assertOk();
        $this->assertNotEmpty($allowed->json('data'));
    }

    public function test_a_suppliers_own_history_carries_the_notes_written_about_them(): void
    {
        // Arrange
        $vendor = Vendor::factory()->create();
        $comment = Comment::factory()->on($vendor)->create();
        $headers = $this->headers($this->admin());

        // Act
        $this->forgetAuth();
        $response = $this->withHeaders($headers)->getJson("/api/v1/vendors/{$vendor->id}/logs");

        // Assert — «ماذا حدث لهذا المورد؟» includes what was said about them, the same way a
        // customer's history includes theirs.
        $response->assertOk();

        $noteEntries = array_filter(
            $response->json('data'),
            fn (array $entry) => $entry['subject_type'] === 'comment'
                && (int) $entry['subject_id'] === $comment->id,
        );

        $this->assertNotEmpty($noteEntries);
    }
}
