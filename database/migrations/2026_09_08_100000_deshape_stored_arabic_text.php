<?php

use App\Application\Api\V1\Middleware\DeshapeArabicInput;
use App\Console\Commands\DeshapeStoredText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Folds the pre-shaped Arabic that is already in the tables back into letters.
 *
 * Everything typed from now on is folded at the door by
 * {@see DeshapeArabicInput}; this is for what was typed before
 * it existed. A customer's name carrying one `ﻱ` (U+FEF1) is what stopped order 1228's invoice
 * from being drawn, and the rows around it were keyed on the same keyboard.
 *
 * **The work lives in `text:deshape` rather than here**, because it is worth being able to run
 * again — `--dry-run` first on a live database, then for real, and once more the day somebody
 * imports a spreadsheet. See {@see DeshapeStoredText} for what is folded
 * and what is deliberately left alone.
 *
 * Its own migration, per RULES §8: a schema migration does not carry data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('text:deshape', ['--force' => true]);

        echo Artisan::output();
    }

    public function down(): void
    {
        // Nothing to undo that would not be a guess: which rows this folded, and which were
        // written in letters all along, is not recorded anywhere. Nor would anyone want the
        // shapes back — they are what the invoice could not draw.
    }
};
