<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per thing worth telling somebody about.
 *
 * **The event, not the delivery.** Who receives it lives in `notification_recipients`; this row
 * is written once however many people it reaches, and once for nobody at all when the audience
 * turns out to be empty.
 *
 * **`payload` is a frozen snapshot, and the sentence is rendered from it at read time.** Storing
 * the rendered Arabic instead would mean a typo lives forever in every mailbox that received it,
 * and a renamed customer reading back under their old name. Freezing the facts and rendering the
 * words is what lets the words be fixed by a deploy — see `NotificationResource`.
 *
 * **No `updated_at`.** A notification is immutable: it describes a moment that already happened.
 * The only thing that ever changes is *per person* — whether they have read it — and that is a
 * column on the other table.
 *
 * Deliberately **not** soft deleted and **not** audited, the same exemption `activity_log` and
 * `nawris_webhook_events` carry and for the same reason: this is a high-volume event record, not
 * a business record. Auditing it would write a log row saying a log row arrived, and soft
 * deleting it would defeat the retention prune that keeps the table from growing forever.
 * `ModelConventionsTest` lists all three models explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // A `NotificationType` value — `order.shortage`. Indexed because the retention prune
            // and any future per-type filter both read it.
            $table->string('type', 64)->index();

            // What it is about. A morph *alias* from AuditSubject — `order`, never a PHP class
            // name — so a model moving between contexts does not strand historical rows.
            // Nullable: an announcement is about nothing.
            $table->nullableMorphs('subject');

            // The frozen facts. jsonb rather than json: PostgreSQL stores it parsed, which is
            // what makes a future `payload->>'order_id'` query possible without a rewrite.
            $table->jsonb('payload');

            // Storm control. Two identical events inside the window collapse to one row — see
            // PublishNotification. Nullable, because an announcement is never deduplicated.
            $table->string('dedupe_key', 128)->nullable()->index();

            // Who caused it, so they can be left out of their own notification. Null for
            // anything no person did: a queued job, a console command, a seeder.
            $table->foreignId('causer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
