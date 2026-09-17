<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCategory;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\ProductCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * التصنيفات، بعد أن ابتلعت «النوع».
 *
 * مطبوعة/سادة كانت عموداً على المنتج إلى جانب التصنيف، ولم تكن تدخل في أي حساب — لا في التسعير
 * ولا في التكلفة ولا في المخزن. صارت الآن سطرين في جدول التصنيفات، فبقي في النظام مفهوم واحد
 * يُصنَّف به المنتج بدل اثنين يتنازعان الكلمة. See PRODUCT-CATEGORIES.md.
 *
 * Arrange - Act - Assert throughout.
 */
class ProductCategorySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_catalogue_headings_and_the_two_that_replaced_the_type(): void
    {
        // Act
        $this->seed(ProductCategorySeeder::class);

        // Assert
        foreach (['أكياس', 'علب وكراتين التغليف', 'ستيكرات ومطبوعات أخرى', 'مطبوعة', 'سادة'] as $name) {
            $this->assertDatabaseHas('product_categories', ['name' => $name]);
        }
    }

    public function test_the_catalogue_files_every_bag_under_printed_or_plain(): void
    {
        // Arrange — the categories exist before the products that point at them.
        $this->seed(ProductCategorySeeder::class);

        // Act
        $this->seed(CatalogSeeder::class);

        // Assert — the distinction the dropped column carried survives as a heading: the four
        // per-kilo bags under «سادة», everything else under «مطبوعة».
        $plain = ProductCategory::query()->where('name', 'سادة')->sole();
        $printed = ProductCategory::query()->where('name', 'مطبوعة')->sole();

        $this->assertSame(
            4,
            Product::query()->where('product_category_id', $plain->getKey())->count(),
        );
        $this->assertGreaterThan(
            0,
            Product::query()->where('product_category_id', $printed->getKey())->count(),
        );
        $this->assertSame(0, Product::query()->whereNull('product_category_id')->count());
    }

    public function test_a_product_already_filed_by_hand_is_left_where_it_was_put(): void
    {
        // Arrange — somebody moved a bag to «علب وكراتين التغليف» on purpose.
        $this->seed(ProductCategorySeeder::class);
        $boxes = ProductCategory::query()->where('name', 'علب وكراتين التغليف')->sole();
        $product = Product::factory()->create(['product_category_id' => $boxes->getKey()]);

        // Act
        $this->seed(ProductCategorySeeder::class);

        // Assert
        $this->assertSame($boxes->getKey(), $product->fresh()?->product_category_id);
    }
}
