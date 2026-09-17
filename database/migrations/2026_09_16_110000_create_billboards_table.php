<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop puts in front of the customer app's home screen.
 *
 * **A poster, not a record** — and that is what makes it the one thing in this schema besides
 * delivery locations that staff may genuinely delete. A customer is deactivated and a product is
 * deactivated because orders keep pointing at them; nothing ever points at a banner, so a wrong
 * one is removable rather than something to be lived with. Soft deleted all the same, per
 * RULES.md §10.
 *
 * **The media columns are the `product_images` layer, not a new one.** Disk and path per row and
 * no URL ever stored, so moving to S3 stays a config change with no migration — and the disk is
 * the *public* one, unlike a customer's design: this is the business's own marketing, and a
 * signed link that expires would be a banner that stops loading.
 *
 * **`product_id` and `external_url` are mutually exclusive**, and the check below says so in the
 * database rather than only in a FormRequest. Two destinations would mean a precedence rule
 * somebody has to remember, and the first person to set both would discover which one wins by
 * watching a customer tap it.
 *
 * `starts_at` / `ends_at` are the schedule, and both being null is the ordinary case: most
 * banners run until somebody takes them down. Only the *client* endpoint reads them — the
 * management list shows every row, scheduled or expired, because a banner you cannot see is one
 * you cannot fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billboards', function (Blueprint $table) {
            $table->id();

            // Staff-facing. What a colleague calls this banner in the management list, and the
            // alt text the app draws for a screen reader. Nullable: a picture with no name is
            // still a picture, and the list falls back to the filename.
            $table->string('title')->nullable();

            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width_px')->nullable();
            $table->unsignedInteger('height_px')->nullable();

            // Where a tap leads. `nullOnDelete` rather than cascade: a product going away should
            // cost the shop a link, not the banner it spent money designing.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('external_url')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes()->index();

            // What the client endpoint orders by, every time it is called.
            $table->index(['is_active', 'sort_order']);
        });

        // The rule the FormRequest also states, kept here as well because it is an invariant of
        // the row rather than of one request: a seeder, an import or a future endpoint cannot
        // write a banner with two destinations either.
        DB::statement(<<<'SQL'
            ALTER TABLE billboards
            ADD CONSTRAINT billboards_one_destination
            CHECK (product_id IS NULL OR external_url IS NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('billboards');
    }
};
