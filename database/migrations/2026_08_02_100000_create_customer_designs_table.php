<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's artwork — the image or PDF that gets printed on their bags.
 *
 * Stored against the **customer**, not the shop. A design is what the business is called, and
 * one order is placed for a customer and delivered to whichever of their shops asked; tying
 * artwork to a branch would mean re-uploading the same logo for every branch and then keeping
 * five copies in step.
 *
 * The disk and the path are recorded per row, exactly as `product_images` does, so flipping to
 * S3 is a config change and existing files keep resolving from where they already live. No URL
 * is stored: a URL embeds a bucket, a region and a host, all of which change.
 *
 * **One rule is inverted from product images, and it is the point of the table.** A product
 * photo is deleted for real when it is removed. A design is not: an order printed last year has
 * to be able to show what was printed, and a colleague tidying the picker must not be able to
 * erase it. Deleting a design hides the row; the object stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_designs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            $table->string('disk', 30);
            $table->string('path', 1024);

            $table->string('original_filename')->nullable();

            // Sniffed from the bytes on upload, never taken from the client's claim.
            $table->string('mime_type', 100);

            // DesignKind — `image` or `pdf`. Derived from the sniffed mime, and what tells the
            // app whether it can draw this thing or must hand it to the system viewer.
            $table->string('kind', 10);

            $table->unsignedBigInteger('size_bytes');

            // sha256 of the contents. Makes an upload idempotent: a retry after a dropped
            // connection finds the file already here instead of adding a second copy.
            $table->char('checksum', 64);

            // `integer`, not `unsignedSmallInteger` — see the migration beside this one.
            $table->unsignedInteger('width_px')->nullable();
            $table->unsignedInteger('height_px')->nullable();

            // How staff tell two designs apart when choosing one to print. Load-bearing: there
            // are no PDF thumbnails, so this is the whole identification story.
            $table->string('label')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['customer_id', 'created_at']);
        });

        // The same file twice for one customer is a mistake, not a decision — and this is what
        // lets the upload action answer a retry with the row that already exists. Partial, like
        // every unique index in this schema: a removed design releases its claim.
        DB::statement(
            'CREATE UNIQUE INDEX customer_designs_unique_file_per_customer
             ON customer_designs (customer_id, checksum) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_designs');
    }
};
