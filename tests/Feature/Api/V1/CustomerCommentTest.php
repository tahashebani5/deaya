<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Domain\Comment\Models\Comment;
use App\Domain\Customer\Models\Customer;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Identity\Enums\RoleName;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * What staff write to each other about a customer.
 *
 * The rule this feature exists to keep: **a note is attributed, and attribution is not
 * editable.** Anyone may write one; only its author may rewrite it — or somebody the business
 * has explicitly made a moderator. Editing a colleague's sentence under their name is a
 * different power from editing the customer's phone number, which is exactly why it has a
 * permission of its own.
 *
 * Arrange - Act - Assert throughout.
 */
class CustomerCommentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Somebody who may read customers, and nothing more.
     *
     * That is the whole grant a note-writer needs: a note is a working tool, not a privilege.
     */
    private function reader(): User
    {
        foreach (PermissionName::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo(PermissionName::ViewCustomers->value);

        return $user;
    }

    /** Somebody the business has put over other people's notes. */
    private function moderator(): User
    {
        $user = $this->reader();
        $user->givePermissionTo(PermissionName::ModerateComments->value);

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create();

        Role::findOrCreate(RoleName::Admin->value, 'web');
        $user->syncRoles([RoleName::Admin->value]);

        return $user;
    }

    /**
     * @return array<string, string>
     */
    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    // ─────────────────────────── writing one ───────────────────────────

    public function test_a_reader_may_leave_a_note(): void
    {
        // Arrange — `customers.view` only: writing a note is not managing the customer.
        $user = $this->reader();
        $customer = Customer::factory()->create();

        // Act
        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/comments",
            ['body' => 'يفضّل التسليم صباحاً قبل العاشرة'],
            $this->headers($user),
        );

        // Assert
        $response->assertCreated()
            ->assertJsonPath('data.body', 'يفضّل التسليم صباحاً قبل العاشرة')
            // Who said it travels with it: a note nobody can be asked about is a rumour.
            ->assertJsonPath('data.author.id', $user->id)
            ->assertJsonPath('data.author.name', $user->name)
            ->assertJsonPath('data.edited_at', null)
            // The rules, decided by the server and read by the app — see CUSTOMER-COMMENTS.md.
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_delete', true);

        $this->assertDatabaseHas('comments', [
            'commentable_type' => 'customer',
            'commentable_id' => $customer->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_an_empty_note_is_refused(): void
    {
        // Arrange — a line of spaces satisfies `required` while telling the next reader nothing.
        $user = $this->reader();
        $customer = Customer::factory()->create();

        // Act
        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/comments",
            ['body' => '   '],
            $this->headers($user),
        );

        // Assert
        $response->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_somebody_with_no_customer_access_may_not_write_one(): void
    {
        // Arrange
        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        // Act
        $response = $this->postJson(
            "/api/v1/customers/{$customer->id}/comments",
            ['body' => 'ملاحظة'],
            $this->headers($user),
        );

        // Assert
        $response->assertForbidden();
    }

    // ─────────────────────────── reading them ───────────────────────────

    public function test_notes_come_back_newest_first_and_only_this_customers(): void
    {
        // Arrange
        $user = $this->reader();
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();

        Comment::factory()->on($customer)->create([
            'body' => 'الأقدم',
            'created_at' => now()->subDay(),
        ]);
        Comment::factory()->on($customer)->create([
            'body' => 'الأحدث',
            'created_at' => now(),
        ]);
        Comment::factory()->on($other)->create(['body' => 'عميل آخر']);

        // Act
        $response = $this->getJson(
            "/api/v1/customers/{$customer->id}/comments",
            $this->headers($user),
        );

        // Assert — a note is read to catch up, so the last thing said is the first thing shown.
        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'الأحدث')
            ->assertJsonPath('data.1.body', 'الأقدم');
    }

    public function test_a_deleted_note_is_gone_from_the_list(): void
    {
        // Arrange
        $user = $this->reader();
        $customer = Customer::factory()->create();
        $comment = Comment::factory()->on($customer)->create();
        $comment->delete();

        // Act
        $response = $this->getJson(
            "/api/v1/customers/{$customer->id}/comments",
            $this->headers($user),
        );

        // Assert — soft-deleted, so the history keeps it; the list does not.
        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_reader_is_told_which_notes_are_theirs_to_change(): void
    {
        // Arrange
        $user = $this->reader();
        $customer = Customer::factory()->create();
        Comment::factory()->on($customer)->for($user, 'author')->create(['body' => 'ملاحظتي']);
        Comment::factory()->on($customer)->create(['body' => 'ملاحظة زميل']);

        // Act
        $response = $this->getJson(
            "/api/v1/customers/{$customer->id}/comments",
            $this->headers($user),
        );

        // Assert — the app draws the buttons off these, and the server refuses regardless.
        $mine = collect($response->json('data'))->firstWhere('body', 'ملاحظتي');
        $theirs = collect($response->json('data'))->firstWhere('body', 'ملاحظة زميل');

        $this->assertTrue($mine['can_edit']);
        $this->assertTrue($mine['can_delete']);
        $this->assertFalse($theirs['can_edit']);
        $this->assertFalse($theirs['can_delete']);
    }

    // ─────────────────────────── changing one ───────────────────────────

    public function test_an_author_may_rewrite_their_own_note(): void
    {
        // Arrange
        $user = $this->reader();
        $customer = Customer::factory()->create();
        $comment = Comment::factory()->on($customer)->for($user, 'author')->create();

        // Act
        $response = $this->patchJson(
            "/api/v1/customers/{$customer->id}/comments/{$comment->id}",
            ['body' => 'التصحيح: بعد الظهر وليس صباحاً'],
            $this->headers($user),
        );

        // Assert — and it says it was edited, because a note that changed silently is a note
        // whose reader remembers something that is no longer there.
        $response->assertOk()
            ->assertJsonPath('data.body', 'التصحيح: بعد الظهر وليس صباحاً')
            ->assertJsonPath('data.edited_at', fn (?string $at): bool => $at !== null);
    }

    public function test_a_colleagues_note_may_not_be_rewritten(): void
    {
        // Arrange
        $user = $this->reader();
        $customer = Customer::factory()->create();
        $comment = Comment::factory()->on($customer)->create(['body' => 'كلام زميل']);

        // Act
        $response = $this->patchJson(
            "/api/v1/customers/{$customer->id}/comments/{$comment->id}",
            ['body' => 'كلام آخر باسمه'],
            $this->headers($user),
        );

        // Assert
        $response->assertForbidden();
        $this->assertSame('كلام زميل', $comment->refresh()->body);
    }

    public function test_a_moderator_may_rewrite_anybodys_note(): void
    {
        // Arrange
        $moderator = $this->moderator();
        $customer = Customer::factory()->create();
        $comment = Comment::factory()->on($customer)->create();

        // Act
        $response = $this->patchJson(
            "/api/v1/customers/{$customer->id}/comments/{$comment->id}",
            ['body' => 'تصحيح من المشرف'],
            $this->headers($moderator),
        );

        // Assert
        $response->assertOk()->assertJsonPath('data.body', 'تصحيح من المشرف');
    }

    // ─────────────────────────── removing one ───────────────────────────

    public function test_an_author_may_remove_their_own_note(): void
    {
        // Arrange
        $user = $this->reader();
        $customer = Customer::factory()->create();
        $comment = Comment::factory()->on($customer)->for($user, 'author')->create();

        // Act
        $response = $this->deleteJson(
            "/api/v1/customers/{$customer->id}/comments/{$comment->id}",
            [],
            $this->headers($user),
        );

        // Assert — soft-deleted: the audit trail still answers who wrote what and when.
        $response->assertOk();
        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    public function test_a_colleagues_note_may_not_be_removed(): void
    {
        // Arrange
        $user = $this->reader();
        $customer = Customer::factory()->create();
        $comment = Comment::factory()->on($customer)->create();

        // Act
        $response = $this->deleteJson(
            "/api/v1/customers/{$customer->id}/comments/{$comment->id}",
            [],
            $this->headers($user),
        );

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'deleted_at' => null]);
    }

    public function test_a_moderator_may_remove_anybodys_note(): void
    {
        // Arrange
        $moderator = $this->moderator();
        $customer = Customer::factory()->create();
        $comment = Comment::factory()->on($customer)->create();

        // Act
        $response = $this->deleteJson(
            "/api/v1/customers/{$customer->id}/comments/{$comment->id}",
            [],
            $this->headers($moderator),
        );

        // Assert
        $response->assertOk();
        $this->assertSoftDeleted('comments', ['id' => $comment->id]);
    }

    // ─────────────────────────── scoping ───────────────────────────

    public function test_another_customers_note_is_a_404_here(): void
    {
        // Arrange — the id exists; it just does not belong under this customer.
        $user = $this->reader();
        $customer = Customer::factory()->create();
        $elsewhere = Comment::factory()->on(Customer::factory()->create())->for($user, 'author')->create();

        // Act
        $response = $this->patchJson(
            "/api/v1/customers/{$customer->id}/comments/{$elsewhere->id}",
            ['body' => 'ملاحظة في المكان الخطأ'],
            $this->headers($user),
        );

        // Assert — `scoped()` makes this a 404 by construction, not by a check somebody has to
        // remember to write.
        $response->assertNotFound();
    }

    // ─────────────────────────── the history ───────────────────────────

    public function test_a_notes_history_is_readable_by_an_auditor(): void
    {
        // Arrange
        $admin = $this->admin();
        $customer = Customer::factory()->create();
        $comment = Comment::factory()->on($customer)->create();

        $this->patchJson(
            "/api/v1/customers/{$customer->id}/comments/{$comment->id}",
            ['body' => 'نص معدّل'],
            $this->headers($admin),
        )->assertOk();

        // Act
        $response = $this->getJson(
            "/api/v1/customers/{$customer->id}/comments/{$comment->id}/logs",
            $this->headers($admin),
        );

        // Assert — the project rule: every model soft-deletes and every model has a `/logs`.
        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }
}
