<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سعر السادة — what the press pays the deal for a unit of its plain stock.
 *
 * The owner's rule, 2026-09-06: «الشركة نفسها مطبعة — كأننا بنشروه من المستثمر… أي حاجة تطلع من
 * المخزون الكيلو يمشي بسعر السادة بالوزن… استلم الزبون ما استلمش، المطبعة تتحمّل.»
 *
 * **A term of the deal, typed once while funding it**, beside the percentages and frozen with
 * them: «لو في خيار ف الصفقة نحدد سعر البيع للطباعه (اثناء شراء) — 32 ع الاغلب، مرات ع حسب
 * الصفقة نزيد او انقص، لكن السعر بيمشي ع كل طلبيات الطباعه». A man puts his money in knowing
 * what he sells at; a price read live at the moment stock left would change under a deal he had
 * already entered.
 *
 * **Null is the whole of backward compatibility.** A deal funded without one behaves exactly as
 * every deal built before today: nothing is bought at the shelf, and its profit is the delivered
 * order's, split by `OrderDealSlices` as before. Two mechanisms, and a deal is only ever on one
 * of them.
 *
 * Three decimals, not two: it is a rate per kilogram, and «32.500» is a price a person types.
 * The same scale `stock_batches.unit_cost` carries, which is the number it is compared against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_deals', function (Blueprint $table) {
            $table->decimal('printing_sale_price', 12, 3)->nullable()->after('investor_funded_percent');
        });

        // Zero is not "free", it is "nobody said" — and «nobody said» is already spelled NULL
        // here. A zero price would hand the press the goods for nothing and post a loss to the
        // investor for the whole cost of them.
        DB::statement(<<<'SQL'
            ALTER TABLE investor_deals
            ADD CONSTRAINT investor_deals_printing_sale_price_positive
            CHECK (printing_sale_price IS NULL OR printing_sale_price > 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE investor_deals DROP CONSTRAINT IF EXISTS investor_deals_printing_sale_price_positive');

        Schema::table('investor_deals', function (Blueprint $table) {
            $table->dropColumn('printing_sale_price');
        });
    }
};
