<?php

use App\Domain\Audit\Enums\AuditSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail: one row per thing that happened to a record.
 *
 * Published from spatie/laravel-activitylog and then extended, because the shape the package
 * ships is tuned for "show me a feed" and ours is asked a narrower question — *"what happened
 * to this product?"* — from a per-record screen.
 *
 * **`subject_type` holds a morph alias, not a class name.** `product`, never
 * `App\Domain\Catalog\Models\Product` — see {@see AuditSubject}. A class
 * name in this column would make moving a file rewrite history, and would publish our internal
 * namespace as part of the API.
 *
 * **Nothing here is ever updated.** A row records what was true at one instant; correcting it
 * would defeat the point of keeping it. `updated_at` exists only because the package's model is
 * an ordinary Eloquent model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();

            // Groups a row by the record it belongs to — 'product', 'city' … Indexed because
            // the global feed filters on it.
            $table->string('log_name')->nullable()->index();

            $table->text('description');

            // What it happened to. nullableMorphs indexes (subject_type, subject_id), which is
            // the lookup every per-record endpoint makes.
            $table->nullableMorphs('subject', 'subject');

            // created · updated · deleted · restored — see Spatie's ActivityEvent.
            $table->string('event')->nullable();

            // Who did it. Null for anything the system did without a signed-in user: a seeder,
            // a console command, a queued job.
            $table->nullableMorphs('causer', 'causer');

            // The before/after of the attributes that actually changed.
            $table->json('attribute_changes')->nullable();

            // Free-form extras a caller attached by hand.
            $table->json('properties')->nullable();

            $table->timestamps();
        });

        // The per-record endpoints read one subject's history newest-first and page through it.
        // The (subject_type, subject_id) index alone leaves PostgreSQL sorting the matches; this
        // one hands them back already ordered.
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id', 'id'], 'activity_log_subject_recent_index');

            // The global feed filters by event before ordering.
            $table->index('event', 'activity_log_event_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
