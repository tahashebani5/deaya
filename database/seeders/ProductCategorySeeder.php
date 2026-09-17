<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\ProductionMode;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * التصنيفات التي يعرضها الكتالوج، والمنتجات المسجّلة قبلها.
 *
 * The three headings the public catalogue is already organised under — daaya.ly/catalog.html —
 * so the app and the site agree on what a category *is* from the first day rather than after
 * somebody types three names twice.
 *
 * **Idempotent, and it never overwrites a decision.** Categories are matched by name, and the
 * backfill only touches products that have no category at all: a product somebody has since
 * filed under «علب» is left exactly where they put it.
 *
 * **«مطبوعة» and «سادة» are on the list because «النوع» is not a field any more.** That split
 * lived in a `category` column beside this one and fed no calculation anywhere — see the
 * migration that dropped it — so it became two headings here rather than a second word on every
 * product. Mixing «كيف يُصنع» with «أين يقف في الكتالوج» in one list is the price of having one
 * list, and it is the cheaper of the two.
 *
 * The backfill puts a still-uncategorised product under «أكياس» because, on the day this was
 * written, every product in the system was a bag. That is a fact about this data, not a rule: a
 * box added tomorrow is filed by whoever adds it, and this seeder will not touch it. Products
 * the migration already filed under مطبوعة/سادة are not uncategorised, so it passes them by.
 */
class ProductCategorySeeder extends Seeder
{
    /**
     * Name, description and order — the same three the catalogue prints — plus, for one row, the
     * answer to «هل يُطبع؟».
     *
     * `production_mode` is omitted everywhere it is `in_house`, which is everywhere but «سادة»
     * and «وسيط»: the column defaults to it, and writing it out four times would suggest four
     * decisions were taken when only two were.
     *
     * @var list<array{name: string, description: string, sort_order: int, production_mode?: ProductionMode}>
     */
    private const CATEGORIES = [
        [
            'name' => 'أكياس',
            'description' => 'تشكيلة أكياس مخصصة للتغليف والشحن، مناسبة للمتاجر والمطاعم والمشاريع '
                .'التجارية، مع خيارات متعددة من الخامات والأحجام وإمكانية الطباعة حسب الطلب.',
            'sort_order' => 1,
        ],
        [
            'name' => 'علب وكراتين التغليف',
            'description' => 'صناديق نقل وهدايا توفر الفخامة والحماية الفائقة لمنتجاتك.',
            'sort_order' => 2,
        ],
        [
            'name' => 'ستيكرات ومطبوعات أخرى',
            'description' => 'ملصقات، كروت شكر، وشريط لاصق لتعزيز تفاصيل التغليف.',
            'sort_order' => 3,
        ],
        [
            'name' => 'مطبوعة',
            'description' => 'منتجات تُطبع بالشعار والألوان حسب الطلب.',
            'sort_order' => 4,
        ],
        [
            'name' => 'سادة',
            'description' => 'منتجات بلا طباعة، تُباع غالباً بالوزن.',
            'sort_order' => 5,
            // **The description was already saying it; now something reads it.** «بلا طباعة»
            // means an order made only of these has nothing to design and nothing to print, so
            // it goes جديدة → جاهزة — see `ProductCategory::productionMode()`. It is a fact about
            // *this* heading rather than a rule about headings: one added tomorrow is answered by
            // whoever adds it.
            'production_mode' => ProductionMode::None,
        ],
        [
            'name' => 'وسيط',
            'description' => 'منتجات تبيعها دعاية وينفّذها مورد خارجي، وتُتابَع حتى تصبح جاهزة.',
            'sort_order' => 6,
            // An order made only of these goes جديدة → (قيد التصميم) → قيد التصنيع → جاهزة, names
            // the vendor it was sent to, and takes nothing off our shelf — see
            // OUTSOURCED-PRODUCTS.md. Seeded because the business asked for the heading by name;
            // the products under it, and their cost prices, are theirs to add.
            'production_mode' => ProductionMode::Outsourced,
        ],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $category) {
            ProductCategory::query()->firstOrCreate(
                ['name' => $category['name']],
                [
                    'description' => $category['description'],
                    'sort_order' => $category['sort_order'],
                    'is_active' => true,
                    'production_mode' => $category['production_mode'] ?? ProductionMode::InHouse,
                ],
            );
        }

        $bags = ProductCategory::query()->where('name', 'أكياس')->sole();

        // `whereNull` is the whole safety of this: re-running it changes nothing, and a product
        // moved to another heading stays there.
        $filled = Product::query()
            ->whereNull('product_category_id')
            ->update(['product_category_id' => $bags->getKey()]);

        $this->command?->info("تم تصنيف {$filled} منتجاً تحت «أكياس».");
    }
}
