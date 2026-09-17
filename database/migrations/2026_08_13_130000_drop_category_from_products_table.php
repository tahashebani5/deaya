<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «النوع» — مطبوعة/سادة — becomes two rows in `product_categories`, and its column goes.
 *
 * **It never earned the column.** Nothing read it: not `QuoteProductPrice`, not the order
 * pricing, not the manufacturing cost rates, not the warehouse. It was validated, stored,
 * filtered on and printed — a second word competing with «التصنيف» for the same job. What it
 * genuinely said about billing, `pricing_unit` says on its own: a plain bag is sold by the kilo
 * because it is sold by the kilo, not because of a string beside it.
 *
 * **The distinction is moved, not discarded.** Every product is filed under «مطبوعة» or «سادة»
 * *before* the column is dropped — the only moment both facts exist at once — so a catalogue
 * that used to be readable two ways is still readable one way afterwards. Only a product with
 * no category at all is touched; one somebody filed by hand keeps where they put it.
 *
 * The two headings are created here on demand rather than always: a fresh database has no
 * products to move, and a migration that seeds reference data into every empty install is a
 * seeder wearing the wrong hat. `ProductCategorySeeder` owns the permanent list.
 */
return new class extends Migration
{
    /** The old column's two values, and the heading each becomes. */
    private const HEADINGS = [
        'printed' => ['name' => 'مطبوعة', 'description' => 'منتجات تُطبع بالشعار والألوان حسب الطلب.', 'sort_order' => 4],
        'general' => ['name' => 'سادة', 'description' => 'منتجات بلا طباعة، تُباع غالباً بالوزن.', 'sort_order' => 5],
    ];

    public function up(): void
    {
        foreach (self::HEADINGS as $type => $heading) {
            $orphans = DB::table('products')
                ->where('category', $type)
                ->whereNull('product_category_id');

            // Nothing to file means nothing to create: the heading is only worth a row here if a
            // product is about to point at it.
            if (! $orphans->exists()) {
                continue;
            }

            $categoryId = DB::table('product_categories')->where('name', $heading['name'])->value('id')
                ?? DB::table('product_categories')->insertGetId($heading + [
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            $orphans->update(['product_category_id' => $categoryId]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    /**
     * Reversible in shape, not in fact: the column comes back and every product is called
     * printed unless it is filed under «سادة». That is a reconstruction, and the only honest one
     * available — the value it is rebuilt from is the one this migration moved it to.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('category', 20)->default('printed')->index()->after('features');
        });

        $plainId = DB::table('product_categories')->where('name', 'سادة')->value('id');

        if ($plainId !== null) {
            DB::table('products')->where('product_category_id', $plainId)->update(['category' => 'general']);
        }
    }
};
