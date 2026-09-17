<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a push goes — one row per device signed in to one account.
 *
 * **Hard deleted, and it is the one table in this schema that is.** RULES.md §10 makes soft
 * deletion universal because a business record must survive its own removal; an FCM registration
 * token is not a record of anything, it is a routing address. When Google answers `UNREGISTERED`
 * the address is dead, and keeping the row to push at forever is not history, it is a leak that
 * costs a queued job every time. So there is no `deleted_at` here, and `ModelConventionsTest`
 * exempts the model alongside the two notification tables.
 *
 * **`token` is unique across the whole table, not per user, and that is a security property
 * rather than tidiness.** A counter phone handed from one employee to the next re-registers the
 * same FCM token under a new `user_id`; the unique index forces that to be an *update* of the
 * one row rather than a second row alongside it, so the previous holder stops receiving
 * notifications on a device they no longer have. Registering is therefore an upsert — see
 * RegisterDeviceToken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // FCM registration tokens are long and have no documented maximum. 255 is what every
            // other string column here uses and what the current format fits inside twice over.
            $table->string('token', 255)->unique();

            // A `DevicePlatform` value. Kept because the push payload's override blocks differ:
            // an iPhone needs `apns`, an Android needs a channel id, and a token cannot be asked
            // which it is.
            $table->string('platform', 16);

            // Touched on every successful send, so a prune can eventually drop devices that
            // stopped answering without ever returning an error.
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
