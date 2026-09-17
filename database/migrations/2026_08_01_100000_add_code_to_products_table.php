<?php

use App\Domain\Catalog\Actions\AllocateProductIdentifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The short code every bag is known by — P1, P2, P3 — shown beside its name in the app.
 *
 * It exists so a product can be named without saying the whole Arabic name. "P4، مقاس 45*50"
 * over the phone is unambiguous where أكياس يد داخلية and أكياس يد خارجية differ by one word.
 *
 * **NOT NULL, from this migration onwards.** Unlike the employee code, this one is *derived*:
 * it is always 'P' + the row's id, so every existing row already has a correct value waiting to
 * be written and there is no window in which the column has to tolerate a null. The backfill
 * below is one statement rather than a loop for the same reason — nothing is being allocated,
 * only spelled out. Codes therefore keep step with ids: product 7 is P7, in the database and in
 * support.
 *
 * The unique index is partial, matching every other unique index in this schema: a deleted
 * product releases its code from the index while keeping it on its own row, so an order that
 * names P7 still reads correctly.
 */
return new class extends Migration
{
    private const INDEX = 'products_code_unique';

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('code', 12)->nullable()->after('id');
        });

        DB::statement(
            'UPDATE products SET code = ? || id::text',
            [AllocateProductIdentifier::PREFIX]
        );

        // Raw, not `->change()`: change() restates the whole column definition and quietly drops
        // anything it was not told about. Only the nullability is moving here.
        DB::statement('ALTER TABLE products ALTER COLUMN code SET NOT NULL');

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX.' ON products (code) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('code');
        });
    }
};
