<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * كم رجع إلى الرفّ فعلاً — بوحدة المخزن، لا بوحدة البيع.
 *
 * **الرقم موجودٌ لحظةً واحدة ثم يُمحى.** `RedrawOrderLineStock` يعكس السحب القديم كاملاً ثم
 * يسحب ما بقي، ويكتب `warehouse_quantity` فوق نفسه بالباقي — فالكمية التي خرجت أصلاً تضيع من
 * السطر. ما يبقى هو حركتا مخزن في سجلٍّ آخر، وسؤال «كم كيلو رجع من هذي الطلبية؟» يصير رحلةً
 * إلى شاشة الصنف وطرحاً باليد.
 *
 * **ولماذا لا يُشتقّ؟** لأن الفرق الذي يظهر على البند بوحدة **البيع** (١٠٠ قطعة) ليس هو الذي
 * وُضع على الرفّ بوحدة **المخزن** (٦٫٨ كجم)، ولا سبيل إلى تحويل أحدهما إلى الآخر: البند المباع
 * بالقطعة والمخزون بالكيلو لا يملك وزناً للقطعة — {@see DeductOrderStock} يرفض اختراع واحد —
 * ولذلك يُسأل أمين المخزن عن الراجع على الميزان. جوابه هو هذا العمود.
 *
 * **خالٍ لكل بندٍ لم يرجع منه شيء**، والمطبوع والوسيط منه: أكياسٌ تحمل تصميم الزبون لا يشتريها
 * أحد، وصفرٌ هنا ادّعاءٌ بأن أحداً فتح الرفّ. «لم يُسأل» ليست «لا شيء».
 *
 * لا مفهرس: لا يُبحث به ولا يُرتَّب عليه — يُقرأ مع بنده وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // بجانب ما تعمل معه: الكمية غير المستلَمة تقول «كم ترك الزبون»، وهذا يقول «كم منها
            // عاد إلى الرفّ» — ويُقرآن معاً أو لا يُقرآن.
            $table->decimal('restocked_quantity', 12, 3)->nullable()->after('undelivered_disposition');
        });

        // سالبٌ لا معنى له: هذا ما دخل المخزن، ولا شيء يدخله بالسالب. والصفر يبقى ممكناً —
        // أمينُ مخزنٍ وزن الراجع فوجده لا شيء يُذكر جوابٌ مختلفٌ عن «لم يرجع».
        DB::statement(<<<'SQL'
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_restocked_quantity_not_negative
            CHECK (restocked_quantity IS NULL OR restocked_quantity >= 0)
        SQL);

        // ولا يُكتب إلا حيث رجعت بضاعة: قيمةٌ هنا على بندٍ لا كمية غير مستلَمة له تصف إعادةً
        // لا مصدر لها.
        DB::statement(<<<'SQL'
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_restocked_quantity_needs_an_undelivered_one
            CHECK (restocked_quantity IS NULL OR undelivered_quantity IS NOT NULL)
        SQL);

        // لا تعبئة رجعية. كل تسليمٍ جزئيٍّ سُجِّل قبل اليوم رجعت بضاعته إلى الرفّ فعلاً — الحركة
        // في سجل المخزن — لكن الرقم لم يُحفَظ، و«لا أعرف» أصدق من رقمٍ يُشتقّ الآن من عمودٍ
        // كُتب فوقه.
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_restocked_quantity_needs_an_undelivered_one');
        DB::statement('ALTER TABLE order_items DROP CONSTRAINT IF EXISTS order_items_restocked_quantity_not_negative');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('restocked_quantity');
        });
    }
};
