<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the catalogue asks of a heading that a flat list of names cannot give it: a place
 * in a tree, and a picture.
 *
 * **`parent_id`, one level deep.** «أكياس» may hold «أكياس ورقية» and «أكياس شفافة»; a child may
 * not hold children of its own. The limit is a decision rather than an oversight — nothing in
 * the catalogue is three levels deep, and a tree of arbitrary depth costs every screen a
 * recursive render and every query a recursive CTE for a shape nobody asked for. It is enforced
 * in the request, where it can be explained, and the column stays a plain self-reference so
 * lifting the limit later is a validation change and not a migration.
 *
 * **A product is filed under a leaf.** A heading with children is a heading, not a slot — see
 * StoreProductRequest. That is what keeps «كم منتجاً تحت أكياس؟» a single question with a single
 * answer instead of «مباشرة أم بالمجموع؟».
 *
 * **The image is columns on the row, not a table.** `product_images` is a table because a
 * product has many photos and one of them is primary; a category has exactly one picture, and a
 * second table for a one-to-one would buy a join and a sort order that can never be used. The
 * disk is recorded per row for the reason it always is here: flipping `MEDIA_DISK` to S3 must
 * leave every existing file resolving from where it already lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                // Restrict rather than cascade: deleting a heading that still holds children is
                // refused by DeleteProductCategory with a sentence explaining what to do
                // instead, and this is the floor under that rule for every other caller.
                ->constrained('product_categories')
                ->restrictOnDelete();

            // The picker and the catalogue both read «children of this root», so the index is
            // on the pair the ordering actually uses.
            $table->index(['parent_id', 'sort_order']);

            // Null together or set together — a path with no disk is a file nobody can find.
            $table->string('image_disk', 30)->nullable();
            $table->string('image_path', 1024)->nullable();

            // Lets a client reserve the right space before the picture loads.
            $table->unsignedInteger('image_width_px')->nullable();
            $table->unsignedInteger('image_height_px')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'sort_order']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['image_disk', 'image_path', 'image_width_px', 'image_height_px']);
        });
    }
};
