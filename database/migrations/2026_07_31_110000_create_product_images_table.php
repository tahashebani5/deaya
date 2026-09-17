<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Photos of a product.
 *
 * Two decisions worth knowing:
 *
 * **The disk is stored per row.** Not a global setting read at display time — the disk the file
 * was actually written to. When MEDIA_DISK flips from `public` to `s3`, new uploads go to S3 and
 * every existing image keeps resolving from where it already lives. No migration, no bulk copy,
 * no window where half the catalogue shows broken images.
 *
 * **No URL is stored, only disk + path.** A URL embeds the bucket, region and CDN host, all of
 * which change; a stored one silently rots. The URL is built when it is asked for, which also
 * lets a private bucket hand out a signed link instead of a public one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // Which filesystem disk holds the file, and its key/path on that disk.
            $table->string('disk', 30);
            $table->string('path', 1024);

            // Kept for humans looking at an S3 key that is otherwise a UUID.
            $table->string('original_filename')->nullable();

            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');

            // Lets a client reserve the right space before the image loads.
            $table->unsignedSmallInteger('width_px')->nullable();
            $table->unsignedSmallInteger('height_px')->nullable();

            $table->string('alt_text')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        // At most one primary image per product, enforced by the database rather than by
        // application code alone — a partial unique index is the only thing that holds when two
        // requests both promote an image at the same moment.
        DB::statement(
            'CREATE UNIQUE INDEX product_images_one_primary_per_product
             ON product_images (product_id) WHERE is_primary'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
