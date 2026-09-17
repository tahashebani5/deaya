<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per person a notification reached — the fan-out.
 *
 * **Fanned out on write, not resolved on read.** The alternative — storing an audience
 * descriptor and working out the recipients each time somebody opens the app — would make the
 * unread badge, which is the most-called endpoint in this whole feature, the most expensive
 * query in it. At this business's headcount the row multiplication is nothing; the badge being
 * a single indexed COUNT is everything.
 *
 * `cascadeOnDelete` on both sides is real here, unlike everywhere else in this schema: these
 * rows are genuinely deleted, by the retention prune and by a user being removed, so the
 * database's cascade actually fires (contrast `CascadesSoftDeletes`, which exists precisely
 * because a soft delete never triggers one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Null until opened. The only mutable fact in this feature.
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // A person is notified once, whatever route the audience took to reach them — a
            // definition naming both a permission and a user must not deliver twice. A unique
            // index rather than a check in the action, because two concurrent publishes would
            // both pass an existence check before either committed.
            $table->unique(['notification_id', 'user_id']);

            // The unread badge: `WHERE user_id = ? AND read_at IS NULL`. Also the list itself,
            // which is this index plus a sort on the notification's id.
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
