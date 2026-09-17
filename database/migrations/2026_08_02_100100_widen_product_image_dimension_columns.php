<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a product image be taller than 32,767 pixels.
 *
 * A real bug, found while building the designs table beside it. `width_px` and `height_px` were
 * declared `unsignedSmallInteger`, and **PostgreSQL has no unsigned integers** — Laravel maps
 * that to `smallint`, whose ceiling is 32767 rather than the 65535 the declaration implies.
 *
 * A print-resolution artwork passes that easily: a 40 cm bag face at 300 dpi is about 4,724 px,
 * and a poster-sized scan several times more. The symptom is not a validation message, it is a
 * `QueryException` on upload — the file is already written to disk by then, so it also leaves an
 * orphaned object behind.
 *
 * Forward-only, per RULES §8: `down()` would refuse on any row that has since used the room.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE product_images ALTER COLUMN width_px TYPE integer');
        DB::statement('ALTER TABLE product_images ALTER COLUMN height_px TYPE integer');
    }

    public function down(): void
    {
        // Deliberately empty. Narrowing back would fail on exactly the rows this migration was
        // written to allow.
    }
};
