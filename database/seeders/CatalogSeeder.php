<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\PricingMode;
use App\Domain\Catalog\Enums\PricingUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use App\Domain\Inventory\Actions\ResolveStockItemForVariant;
use App\Domain\Inventory\Models\StockItem;
use App\Domain\Inventory\Models\StockItemGroup;
use Illuminate\Database\Seeder;

/**
 * The real catalogue, transcribed from Info/Dieaya_Print_Catalog_Mockup.pdf.
 *
 * Seeded rather than invented so the API can be tried against the prices the business actually
 * quotes — a made-up price list would hide exactly the rounding and tier-boundary questions that
 * matter here.
 *
 * Tier thresholds read: **1 / 300 / 1000**, inclusive. The catalogue prints "تحت 300 / فوق 300 /
 * فوق 1000", which leaves 300 and 1000 themselves undefined. Inclusive is the commercially
 * sensible reading — a customer ordering exactly 300 gets the 300+ rate — but it is an
 * interpretation, and worth confirming with the business.
 *
 * Idempotent: safe to re-run without duplicating anything.
 */
class CatalogSeeder extends Seeder
{
    /** The three quantity breaks every printed bag shares. */
    private const BREAKS = [1, 300, 1000];

    /**
     * What each printed product is made of — the shelf its sizes draw from.
     *
     * Each becomes a `StockItemGroup`, and the product is filed under it — so every size the
     * product carries resolves to that material at that size, and one stock item serves however
     * many products name it.
     *
     * Today each printed product names a different material, so nothing shares yet. The sharing
     * arrives the moment the سادة products below stop being sold by the kilo and gain real sizes:
     * point «أكياس الشحن السادة» at the «كيس شحن» group and its 25*35 lands on the identical row
     * «أكياس الشحن» already uses, with nobody linking anything by hand.
     *
     * @var array<string, string>
     */
    private const MATERIALS = [
        'shipping-bag' => 'كيس شحن',
        'outer-handle-bag' => 'كيس يد خارجية',
        'inner-handle-bag' => 'كيس يد داخلية',
        'transparent-zipper-bag' => 'كيس شفاف بسحاب',
        'paper-bag' => 'كيس ورقي',
    ];

    public function run(): void
    {
        // The headings have to exist before anything can point at one, and this seeder is run on
        // its own often enough that depending on DatabaseSeeder's order would be a trap.
        $this->callOnce(ProductCategorySeeder::class);

        foreach ($this->printedBags() as $sortOrder => $definition) {
            $this->seedPrinted($definition, $sortOrder);
        }

        $this->seedQuoteOnRequest();
        $this->seedGeneralPerKilo();
    }

    /**
     * The id of one catalogue heading, resolved once per run.
     *
     * @var array<string, int>
     */
    private array $categoryIds = [];

    /**
     * The material — «كيس شحن» — every size of it is filed under.
     *
     * @var array<string, StockItemGroup>
     */
    private array $groups = [];

    private function group(string $name, PricingUnit $defaultUnit): StockItemGroup
    {
        return $this->groups[$name] ??= StockItemGroup::query()->updateOrCreate(
            ['name' => $name],
            ['default_unit' => $defaultUnit, 'is_active' => true, 'sort_order' => 0],
        );
    }

    /**
     * The shelf for one material at one size, created once and reused by every size that names it.
     *
     * `updateOrCreate` on (group, size) is what makes the sharing automatic: two products naming
     * the same material at the same size get the same row without either of them knowing the
     * other exists — which is the whole point of the table. It is the same match
     * {@see ResolveStockItemForVariant} makes at runtime, written
     * out here because the seeder builds its variants directly rather than through
     * `SyncProductVariants`.
     *
     * The name is the group's, never passed in: a grouped item carries its material's name, and
     * that is what keeps `(name, size)` able to identify exactly one shelf.
     */
    private function stockItemId(StockItemGroup $group, ?int $width, ?int $height): int
    {
        return (int) StockItem::query()->updateOrCreate(
            ['stock_item_group_id' => $group->getKey(), 'width_cm' => $width, 'height_cm' => $height],
            ['name' => $group->name, 'unit' => $group->default_unit, 'is_active' => true, 'sort_order' => 0],
        )->getKey();
    }

    /**
     * «مطبوعة» or «سادة» — the two headings that replaced the old مطبوعة/سادة column.
     */
    private function categoryId(string $name): int
    {
        return $this->categoryIds[$name] ??= (int) ProductCategory::query()
            ->where('name', $name)
            ->sole()
            ->getKey();
    }

    /**
     * Per-piece printed bags: a size on each row, the three quantity breaks across.
     *
     * @return array<int, array{slug: string, name: string, features: list<string>, min: int, sizes: array<string, array{int, int, list<string>}>}>
     */
    private function printedBags(): array
    {
        return [
            [
                'slug' => 'shipping-bag',
                'name' => 'أكياس الشحن',
                'features' => [
                    'مقاومة للماء والتمزق',
                    'لاصق قوي واحترافي',
                    'يحافظ على خصوصية المنتج',
                    'إمكانية طباعة الشعار والألوان',
                ],
                'min' => 100,
                // label => [width, height, [price under 300, from 300, from 1000]]
                'sizes' => [
                    '25*35' => [25, 35, ['1.10', '0.95', '0.85']],
                    '35*40' => [35, 40, ['1.20', '1.05', '0.95']],
                    '45*50' => [45, 50, ['1.65', '1.55', '1.40']],
                    '50*60' => [50, 60, ['1.99', '1.90', '1.80']],
                ],
            ],
            [
                'slug' => 'outer-handle-bag',
                'name' => 'أكياس يد خارجية',
                'features' => ['مظهر أنيق واحترافي', 'متينة وتتحمل الأوزان', 'مثالية للملابس والأحذية'],
                'min' => 100,
                'sizes' => [
                    '30*30' => [30, 30, ['1.24', '1.14', '0.99']],
                    '40*40' => [40, 40, ['1.50', '1.40', '1.25']],
                    '50*50' => [50, 50, ['1.81', '1.71', '1.56']],
                    '60*60' => [60, 60, ['2.13', '2.03', '1.88']],
                ],
            ],
            [
                'slug' => 'inner-handle-bag',
                'name' => 'أكياس يد داخلية',
                'features' => ['مناسبة للمحلات والصيدليات', 'طباعة واضحة بألوان جذابة', 'خفيفة وعملية'],
                'min' => 100,
                'sizes' => [
                    '19*30' => [19, 30, ['1.05', '0.95', '0.80']],
                    '25*35' => [25, 35, ['1.16', '1.06', '0.91']],
                    '30*40' => [30, 40, ['1.31', '1.21', '1.06']],
                    '37*50' => [37, 50, ['1.54', '1.44', '1.29']],
                ],
            ],
            [
                'slug' => 'transparent-zipper-bag',
                'name' => 'الأكياس الشفافة (بسحاب)',
                'features' => ['مثالية لحفظ المنتجات وحمايتها', 'مع إمكانية الطباعة الكاملة'],
                'min' => 100,
                'sizes' => [
                    '27*35' => [27, 35, ['1.15', '1.05', '1.00']],
                    '35*40' => [35, 40, ['1.35', '1.25', '1.15']],
                ],
            ],
            [
                'slug' => 'paper-bag',
                'name' => 'أكياس ورقية عادية',
                'features' => ['تناسب الأشياء خفيفة الوزن', 'إمكانية الطباعة عليها بكل سهولة'],
                'min' => 100,
                'sizes' => [
                    '12*15' => [12, 15, ['1.73', '1.63', '1.48']],
                    '16*22' => [16, 22, ['2.03', '1.93', '1.78']],
                    '22*26' => [22, 26, ['2.25', '2.15', '2.00']],
                    '26*32' => [26, 32, ['2.63', '2.53', '2.38']],
                    '31*40' => [31, 40, ['3.00', '2.90', '2.75']],
                    '45*55' => [45, 55, ['3.38', '3.28', '3.13']],
                ],
            ],
        ];
    }

    /**
     * @param  array{slug: string, name: string, features: list<string>, min: int, sizes: array<string, array{int, int, list<string>}>}  $definition
     */
    private function seedPrinted(array $definition, int $sortOrder): void
    {
        $group = $this->group(self::MATERIALS[$definition['slug']], PricingUnit::Piece);

        $product = Product::query()->updateOrCreate(
            ['slug' => $definition['slug']],
            [
                'name' => $definition['name'],
                'features' => $definition['features'],
                'product_category_id' => $this->categoryId('مطبوعة'),
                // The material every one of this product's sizes is filed under.
                'stock_item_group_id' => $group->getKey(),
                'pricing_unit' => PricingUnit::Piece,
                'pricing_mode' => PricingMode::Tiered,
                'min_order_quantity' => $definition['min'],
                'is_active' => true,
                'sort_order' => $sortOrder,
            ],
        );

        $order = 0;

        foreach ($definition['sizes'] as $label => [$width, $height, $prices]) {
            $variant = $product->variants()->updateOrCreate(
                ['label' => $label],
                [
                    'stock_item_id' => $this->stockItemId($group, $width, $height),
                    'width_cm' => $width,
                    'height_cm' => $height,
                    'is_active' => true,
                    'sort_order' => $order++,
                ],
            );

            // Rewritten wholesale, so re-running never leaves a stale break behind.
            $variant->priceTiers()->delete();

            foreach (self::BREAKS as $index => $minQuantity) {
                $variant->priceTiers()->create([
                    'min_quantity' => $minQuantity,
                    'unit_price' => $prices[$index],
                ]);
            }
        }
    }

    /**
     * The reinforced 3D paper bags: no listed prices, minimum 200, sizes agreed per order.
     */
    private function seedQuoteOnRequest(): void
    {
        $product = Product::query()->updateOrCreate(
            ['slug' => 'paper-bag-3d'],
            [
                'name' => 'أكياس ورقية 3D (مقواة)',
                'description' => 'لا يتم إدراج الأسعار بشكل مباشر نظراً لاختلاف المقاسات والمواصفات. '
                    .'يرجى إرسال تفاصيل المقاس والكمية المطلوبة ليتم تحديد السعر وتقديم العرض المناسب.',
                'features' => ['مناسبة للملابس، العطور، الهدايا', 'تعكس هوية علامتك التجارية باحترافية'],
                'product_category_id' => $this->categoryId('مطبوعة'),
                // **No material, deliberately.** The sizes are agreed per order, so there is
                // nothing for a size to resolve against — and this is the nullable case
                // `products.stock_item_group_id` exists for. Its single placeholder variant stays
                // unlinked, and any attempt to move stock against it is refused by name.
                'stock_item_group_id' => null,
                'pricing_unit' => PricingUnit::Piece,
                'pricing_mode' => PricingMode::QuoteOnRequest,
                'min_order_quantity' => 200,
                'is_active' => true,
                'sort_order' => 5,
            ],
        );

        // A single placeholder variant so the product still has something to order against.
        // It deliberately carries no price tiers — and no stock item either: the size is agreed
        // per order, so there is no pile to point at until one is. This is the nullable case
        // `product_variants.stock_item_id` exists for, and any attempt to fulfil against it is
        // refused by name rather than dereferenced.
        $product->variants()->updateOrCreate(
            ['label' => 'حسب الطلب'],
            ['stock_item_id' => null, 'width_cm' => null, 'height_cm' => null, 'is_active' => true, 'sort_order' => 0],
        );
    }

    /**
     * Plain (سادة) bags sold by the kilo. One price each, so a single tier starting at 1 kg —
     * the same shape as a tiered product, which keeps pricing to one code path.
     */
    private function seedGeneralPerKilo(): void
    {
        $perKilo = [
            'general-shipping-bag' => ['أكياس الشحن السادة', '32.000'],
            'general-transparent-bag' => ['الأكياس الشفافة السادة', '49.000'],
            'general-outer-handle-bag' => ['أكياس اليد الخارجية السادة', '36.000'],
            'general-inner-handle-bag' => ['أكياس اليد الداخلية السادة', '23.000'],
        ];

        $sortOrder = 10;

        foreach ($perKilo as $slug => [$name, $pricePerKilo]) {
            $group = $this->group($name, PricingUnit::Kilogram);

            $product = Product::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'features' => ['بدون طباعة', 'تُباع بالكيلو'],
                    'product_category_id' => $this->categoryId('سادة'),
                    'stock_item_group_id' => $group->getKey(),
                    'pricing_unit' => PricingUnit::Kilogram,
                    'pricing_mode' => PricingMode::Tiered,
                    'min_order_quantity' => 1,
                    'is_active' => true,
                    'sort_order' => $sortOrder++,
                ],
            );

            // Unsized and weighed, because that is how these are sold today — so each gets its
            // own kilogram shelf rather than sharing a sized one with its printed counterpart.
            // See the note on self::MATERIALS: the day these gain sizes, they point at the
            // printed product's items instead and one pile serves both.
            $variant = $product->variants()->updateOrCreate(
                ['label' => 'سادة'],
                [
                    'stock_item_id' => $this->stockItemId($group, null, null),
                    'width_cm' => null,
                    'height_cm' => null,
                    'is_active' => true,
                    'sort_order' => 0,
                ],
            );

            $variant->priceTiers()->delete();
            $variant->priceTiers()->create(['min_quantity' => 1, 'unit_price' => $pricePerKilo]);
        }
    }
}
