<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What staff write to each other about a customer.
 *
 * Not `customers.notes`, which would have been one column and a race between two people saving
 * the same screen. A note is said *by somebody, at a time*, and the next person reads it to
 * catch up — so it is a row per sentence, attributed, ordered.
 *
 * Against the **customer**, not the shop and not the order: «لا يردّ إلا على واتساب» is true of
 * the person however many branches they have and whichever order is open. An order-level note is
 * a separate table the day one is asked for — see CUSTOMER-COMMENTS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // Who said it. `restrictOnDelete` rather than nullable: a note whose author has been
            // erased is a sentence nobody can be asked about, and users are not deleted in this
            // system anyway — this states the assumption instead of leaving it to habit.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->text('body');

            // Null means «as it was written». Kept as its own column rather than compared
            // against `updated_at`, which moves for reasons that are not edits — a soft delete
            // touches it too, and «عُدّلت» under a note nobody changed is a lie.
            $table->timestamp('edited_at')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            // The list is «this customer's, newest first» and nothing else.
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_comments');
    }
};
