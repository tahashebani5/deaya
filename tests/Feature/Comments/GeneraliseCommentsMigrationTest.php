<?php

declare(strict_types=1);

namespace Tests\Feature\Comments;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * الترحيل الذي عمّم الملاحظات — وما لا يجوز أن يسقط في الطريق.
 *
 * `customer_comments` becomes `comments`, and three things travel with it that a rename alone
 * would leave behind: the notes themselves, the history written about them, and the permission
 * that decides who may edit somebody else's. Each is a row somebody already has on the trial
 * server, so each gets a test that puts it in front of the migration and reads it out the other
 * side.
 *
 * `DatabaseMigrations` rather than `RefreshDatabase`: this test runs DDL of its own, and the
 * transaction the other trait holds open has no business wrapping it.
 *
 * Arrange - Act - Assert.
 */
class GeneraliseCommentsMigrationTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Steps back until the old table exists again, rather than assuming it is one step away.
     *
     * Every migration added after this one puts another step between here and there — searching
     * for the schema the test is *about* is what keeps it from breaking on unrelated work.
     *
     * **The bound is the number of migrations there are, not a number somebody picked.** It used
     * to be a hand-written 20, which worked until the twenty-first migration landed after this
     * one and put the schema under test just out of reach — failing with «relation
     * customer_comments does not exist», which reads as a broken migration rather than a cap
     * that ran out. Counting the files can never be wrong, and needs nobody to remember it.
     */
    private function rollBackToTheSchemaThatStillHadCustomerComments(): void
    {
        $mostSteps = count(glob(database_path('migrations/*.php')) ?: []);

        for ($step = 0; $step < $mostSteps; $step++) {
            if (Schema::hasTable('customer_comments')) {
                return;
            }

            Artisan::call('migrate:rollback', ['--step' => 1]);
        }
    }

    /** @return array{customer: int, user: int} */
    private function aCustomerAndAnAuthor(): array
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'محمد',
            'email' => 'mohamed@example.test',
            'phone' => '0911111111',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'code' => 'C900',
            'name' => 'مطبعة النور',
            'phone' => '0910000000',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['customer' => $customerId, 'user' => $userId];
    }

    public function test_it_carries_every_note_over_to_the_polymorphic_table(): void
    {
        // Arrange — a note as it stands today, against a customer.
        $this->rollBackToTheSchemaThatStillHadCustomerComments();
        $this->assertTrue(Schema::hasTable('customer_comments'));

        ['customer' => $customerId, 'user' => $userId] = $this->aCustomerAndAnAuthor();

        $commentId = DB::table('customer_comments')->insertGetId([
            'customer_id' => $customerId,
            'user_id' => $userId,
            'body' => 'لا يردّ إلا على واتساب',
            'edited_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Act
        Artisan::call('migrate');

        // Assert — same id, same author, same sentence, now pointing at its owner through the
        // morph pair. The id matters: a note that came back renumbered would orphan its history.
        $this->assertFalse(Schema::hasTable('customer_comments'));

        $comment = DB::table('comments')->find($commentId);

        $this->assertNotNull($comment);
        $this->assertSame('لا يردّ إلا على واتساب', $comment->body);
        $this->assertSame($userId, $comment->user_id);
        $this->assertSame($customerId, (int) $comment->commentable_id);
        // The short name from the morph map, never a PHP class path — the same value
        // `activity_log` writes, and one that survives a file being moved.
        $this->assertSame('customer', $comment->commentable_type);
    }

    public function test_it_brings_the_history_written_about_a_note_with_it(): void
    {
        // Arrange
        $this->rollBackToTheSchemaThatStillHadCustomerComments();
        ['customer' => $customerId, 'user' => $userId] = $this->aCustomerAndAnAuthor();

        $commentId = DB::table('customer_comments')->insertGetId([
            'customer_id' => $customerId,
            'user_id' => $userId,
            'body' => 'يفضّل التسليم صباحاً',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $logId = DB::table('activity_log')->insertGetId([
            'log_name' => 'default',
            'description' => 'created',
            'subject_type' => 'customer_comment',
            'subject_id' => $commentId,
            'causer_type' => 'user',
            'causer_id' => $userId,
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Act
        Artisan::call('migrate');

        // Assert — «من كتب هذه الملاحظة؟» stays answerable. A row still saying
        // `customer_comment` would be a history pointing at a subject the code no longer knows.
        $this->assertSame('comment', DB::table('activity_log')->find($logId)->subject_type);
    }

    public function test_it_renames_the_moderation_permission_without_dropping_it(): void
    {
        // Arrange — the permission as it is granted today: a row, and a role holding it.
        $this->rollBackToTheSchemaThatStillHadCustomerComments();

        $permissionId = DB::table('permissions')->insertGetId([
            'name' => 'customers.comments.moderate',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $roleId = DB::table('roles')->insertGetId([
            'name' => 'مشرف',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_has_permissions')->insert([
            'permission_id' => $permissionId,
            'role_id' => $roleId,
        ]);

        // Act
        Artisan::call('migrate');

        // Assert — renamed in place, so every role holding it still does. Dropping and
        // recreating would have silently taken it away from everybody who had it.
        $permission = DB::table('permissions')->find($permissionId);

        $this->assertSame('comments.moderate', $permission->name);
        $this->assertDatabaseHas('role_has_permissions', [
            'permission_id' => $permissionId,
            'role_id' => $roleId,
        ]);
        $this->assertDatabaseMissing('permissions', ['name' => 'customers.comments.moderate']);
    }

    public function test_a_note_removed_before_the_move_is_still_removed_after_it(): void
    {
        // Arrange — a soft-deleted note. The whole point of the soft delete is that «من حذف
        // الملاحظة؟» survives, so the migration must not resurrect it.
        $this->rollBackToTheSchemaThatStillHadCustomerComments();
        ['customer' => $customerId, 'user' => $userId] = $this->aCustomerAndAnAuthor();

        $commentId = DB::table('customer_comments')->insertGetId([
            'customer_id' => $customerId,
            'user_id' => $userId,
            'body' => 'ملاحظة محذوفة',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Act
        Artisan::call('migrate');

        // Assert
        $this->assertNotNull(DB::table('comments')->find($commentId)->deleted_at);
    }
}
