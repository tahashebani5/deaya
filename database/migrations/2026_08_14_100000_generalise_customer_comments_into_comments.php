<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الملاحظات تصير عامّة: `customer_comments` ← `comments`.
 *
 * The table was written against the customer alone, with a note in CUSTOMER-COMMENTS.md saying
 * generalising it before a second case existed would be an abstraction with no reason. The
 * second case is here — a note on a supplier — so the column becomes a morph pair.
 *
 * **A rename, not a new table.** Ids stay what they are, which is what keeps every `activity_log`
 * row about a note pointing at the note it was written about.
 *
 * Three things travel with the rename, and each is a row somebody already has on the trial
 * server: the notes, their history, and the permission that says who may edit somebody else's.
 * See GENERAL-COMMENTS.md §١.
 */
return new class extends Migration
{
    /** What the morph column holds for a customer's note — the short name, never a class path. */
    private const CUSTOMER_MORPH = 'customer';

    public function up(): void
    {
        Schema::rename('customer_comments', 'comments');

        Schema::table('comments', function (Blueprint $table): void {
            // Nullable to begin with: the rows already in the table have nothing to put here
            // until the statement below fills them in.
            $table->string('commentable_type')->nullable();
            $table->unsignedBigInteger('commentable_id')->nullable();
        });

        DB::table('comments')->update([
            'commentable_type' => self::CUSTOMER_MORPH,
            'commentable_id' => DB::raw('customer_id'),
        ]);

        // The foreign key goes with the column: a polymorphic owner cannot be constrained,
        // which is the one thing this shape costs. Nothing in this system deletes a customer
        // outright, so what the constraint protected against was already impossible.
        //
        // **Dropped by name, and by both names.** Postgres does not rename a constraint when its
        // table is renamed, so a database that has always called this table `customer_comments`
        // still calls the key `customer_comments_customer_id_foreign` — while one that has been
        // through `down()` and back carries the name it was rebuilt under. `IF EXISTS` on both is
        // what makes this migration survive a rollback, which is the only way anybody tests it.
        foreach (['customer_comments_customer_id_foreign', 'comments_customer_id_foreign'] as $key) {
            DB::statement("ALTER TABLE comments DROP CONSTRAINT IF EXISTS {$key}");
        }

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropColumn('customer_id');

            $table->string('commentable_type')->nullable(false)->change();
            $table->unsignedBigInteger('commentable_id')->nullable(false)->change();

            // The list is «this record's notes, newest first» and nothing else — the same shape
            // the old `['customer_id', 'created_at']` index served.
            $table->index(['commentable_type', 'commentable_id', 'created_at'], 'comments_owner_index');
        });

        // The history of every note already written. Without this, «من حذف الملاحظة؟» stops
        // being answerable for everything older than this deployment: the rows would still name
        // a subject type no morph map resolves.
        DB::table('activity_log')
            ->where('subject_type', 'customer_comment')
            ->update(['subject_type' => 'comment']);

        // **Renamed, never dropped and recreated.** The row is granted to roles through
        // `role_has_permissions`, and a new row would be a new id — quietly taking the power
        // away from everybody who holds it. One permission now, not one per kind of record: the
        // question it answers — «هل يعدّل هذا الموظف كلام زميله؟» — is one question.
        DB::table('permissions')
            ->where('name', 'customers.comments.moderate')
            ->update(['name' => 'comments.moderate']);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'comments.moderate')
            ->update(['name' => 'customers.comments.moderate']);

        DB::table('activity_log')
            ->where('subject_type', 'comment')
            ->update(['subject_type' => 'customer_comment']);

        // Anything written against something other than a customer has no column to go back to.
        // Removed rather than carried into a shape that cannot hold it — going back is going
        // back to a customers-only feature, and a supplier's note was never part of it.
        DB::table('comments')->where('commentable_type', '!=', self::CUSTOMER_MORPH)->delete();

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropIndex('comments_owner_index');
            $table->unsignedBigInteger('customer_id')->nullable();
        });

        DB::table('comments')->update(['customer_id' => DB::raw('commentable_id')]);

        Schema::table('comments', function (Blueprint $table): void {
            $table->unsignedBigInteger('customer_id')->nullable(false)->change();
            // Named as the original table named it, so a second run of `up()` finds the key
            // where a never-rolled-back database keeps it.
            $table->foreign('customer_id', 'customer_comments_customer_id_foreign')
                ->references('id')->on('customers')->cascadeOnDelete();
            $table->dropColumn(['commentable_type', 'commentable_id']);
            $table->index(['customer_id', 'created_at']);
        });

        Schema::rename('comments', 'customer_comments');
    }
};
