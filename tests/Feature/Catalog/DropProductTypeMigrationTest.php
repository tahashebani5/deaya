<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * الترحيل الذي حذف «النوع» — والخطوة الوحيدة فيه التي لا يمكن تكرارها.
 *
 * Dropping a column is the one thing in that migration that cannot be undone by running it
 * again, and the move that precedes it is the only moment مطبوعة/سادة and «التصنيف» both exist.
 * So this rolls the migration back, puts a product of each old type in front of it, and runs it
 * forward — the exact sequence a real database will see, against the disposable test one.
 *
 * `DatabaseMigrations` rather than `RefreshDatabase`: this test runs DDL of its own, and the
 * transaction the other trait holds open has no business wrapping it.
 *
 * Arrange - Act - Assert.
 */
class DropProductTypeMigrationTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Steps back until «النوع» exists again, rather than assuming it is one step away.
     *
     * It was the last migration on the day this test was written and has not been since — every
     * migration added after it puts another step between here and there. Searching for the
     * schema this test is *about* is what keeps it from breaking on work that has nothing to do
     * with it.
     *
     * **And the step counter is gone, because the counter was the bug.** It was 20 once; the
     * twentieth migration after the drop failed this test on work unrelated to «النوع», so it
     * was raised to 80 — and the eightieth did it again, on the customer app's support tables.
     * A number tied to how many migrations exist has to be raised every time that grows, which
     * is a maintenance task nobody will remember and a red build for whoever happens to add the
     * one that tips it.
     *
     * The honest bound is the schema itself: each pass rolls back exactly one migration, so the
     * loop cannot run longer than there are migrations to undo. Running out without finding the
     * column means the migration this test is about is gone — a real failure, and said so.
     */
    private function rollBackToTheSchemaThatStillHadTheColumn(): void
    {
        while (! Schema::hasColumn('products', 'category')) {
            if (DB::table('migrations')->count() === 0) {
                self::fail(
                    'Rolled the whole schema back without ever finding «النوع» on products — '
                    .'the migration this test exists to exercise is no longer there.'
                );
            }

            Artisan::call('migrate:rollback', ['--step' => 1]);
        }
    }

    public function test_it_files_every_product_by_its_old_type_before_dropping_the_column(): void
    {
        // Arrange — back to the schema that still had «النوع», and one product of each kind
        // sitting in it uncategorised, exactly as a pre-categories product does.
        $this->rollBackToTheSchemaThatStillHadTheColumn();
        $this->assertTrue(Schema::hasColumn('products', 'category'));

        foreach ([['printed-one', 'printed', 'P1'], ['plain-one', 'general', 'P2']] as [$slug, $type, $code]) {
            DB::table('products')->insert([
                'slug' => $slug,
                'name' => $slug,
                'code' => $code,
                'category' => $type,
                'product_category_id' => null,
                'pricing_unit' => 'piece',
                'pricing_mode' => 'tiered',
                'min_order_quantity' => 1,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Act
        Artisan::call('migrate');

        // Assert — the column is gone and the distinction it carried is not.
        $this->assertFalse(Schema::hasColumn('products', 'category'));

        $headingOf = fn (string $slug) => DB::table('products')
            ->join('product_categories', 'products.product_category_id', '=', 'product_categories.id')
            ->where('products.slug', $slug)
            ->value('product_categories.name');

        $this->assertSame('مطبوعة', $headingOf('printed-one'));
        $this->assertSame('سادة', $headingOf('plain-one'));
    }

    public function test_it_leaves_a_product_somebody_already_filed_where_they_put_it(): void
    {
        // Arrange — a plain bag deliberately filed under «علب وكراتين التغليف».
        $this->rollBackToTheSchemaThatStillHadTheColumn();

        $boxes = DB::table('product_categories')->insertGetId([
            'name' => 'علب وكراتين التغليف',
            'is_active' => true,
            'sort_order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('products')->insert([
            'slug' => 'filed-by-hand',
            'name' => 'filed-by-hand',
            'code' => 'P3',
            'category' => 'general',
            'product_category_id' => $boxes,
            'pricing_unit' => 'kilogram',
            'pricing_mode' => 'tiered',
            'min_order_quantity' => 1,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Act
        Artisan::call('migrate');

        // Assert — a decision somebody took by hand outranks a backfill.
        $this->assertSame(
            $boxes,
            DB::table('products')->where('slug', 'filed-by-hand')->value('product_category_id'),
        );
    }
}
