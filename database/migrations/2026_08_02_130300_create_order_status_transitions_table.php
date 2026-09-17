<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every move an order made, in order.
 *
 * **This is not a second audit trail, and the distinction is worth stating.** `activity_log`
 * already records that `status` went from one value to another and who did it — it records that
 * for every column of every model, generically and without knowing what any of them mean. This
 * table exists for the questions that are specific to the machine: how long an order sat in
 * printing, how often orders to بنغازي come back, which clerk cancelled and what they said.
 * Answering those from a generic change feed means parsing JSON properties and pairing rows by
 * hand, forever.
 *
 * `from_status` is null exactly once per order — the row written when it is created. That makes
 * "when was this taken" a query on this table rather than a special case somewhere else.
 *
 * **Rows are written once and never edited.** The model still soft deletes and is audited,
 * because every model here does and a test enforces it; the point of the rule is that a history
 * with an exception in it is a history nobody can trust.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_transitions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // Null only for the row that records the order being taken.
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);

            // Mandatory for a cancellation, optional elsewhere — OrderStatus::requiresReason()
            // is the single place that decides, so a future status can demand one without this
            // schema changing.
            $table->text('reason')->nullable();

            // Nullable so a console command or a seeder can move an order without inventing a
            // user; everything through the API carries the signed-in one.
            $table->foreignId('user_id')->nullable()->constrained('users');

            $table->timestamps();
            $table->softDeletes()->index();

            // The order's own timeline, and the "how long in each status" reports built on it.
            $table->index(['order_id', 'created_at']);
            $table->index(['to_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_transitions');
    }
};
