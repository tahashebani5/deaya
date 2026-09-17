<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\ProductionMode;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * الترحيل الذي حوّل `skips_production` إلى `production_mode` — والسؤال الوحيد الذي يهم فيه.
 *
 * **Every heading must come out of it deciding exactly what it decided going in.** The boolean it
 * replaces is what puts an order on the short road, so a backfill that got one row wrong would
 * silently re-route every order taken under that heading afterwards — and would do it quietly,
 * because a wrong road still produces a working order.
 *
 * So this rolls back to the schema that still had the boolean, puts a row of each kind in front
 * of it, and runs forward — the exact sequence a real database sees, against the disposable test
 * one. The same shape as `DropProductTypeMigrationTest`, for the same reason: dropping a column
 * is the one step that cannot be repeated, and the moment before it is the only moment both
 * columns exist.
 *
 * `DatabaseMigrations` rather than `RefreshDatabase`: this runs DDL of its own, and the
 * transaction the other trait holds open has no business wrapping it.
 *
 * Arrange - Act - Assert.
 */
class ProductionModeMigrationTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Steps back until the boolean exists again, rather than assuming it is one step away.
     *
     * Generously bounded, and the loop ends the moment it finds the column — every migration
     * added after this one puts another step between here and there, and a tight count would turn
     * unrelated work into a failure in this file. See the same note in
     * `DropProductTypeMigrationTest`.
     */
    private function rollBackToTheSchemaThatStillHadTheBoolean(int $mostSteps = 80): void
    {
        for ($step = 0; $step < $mostSteps; $step++) {
            if (Schema::hasColumn('product_categories', 'skips_production')) {
                return;
            }

            Artisan::call('migrate:rollback', ['--step' => 1]);
        }
    }

    public function test_it_preserves_every_headings_answer_before_dropping_the_boolean(): void
    {
        // Arrange — back to the boolean, and one heading of each kind sitting in it.
        $this->rollBackToTheSchemaThatStillHadTheBoolean();
        $this->assertTrue(Schema::hasColumn('product_categories', 'skips_production'));

        foreach ([['سادة اختبار', true], ['مطبوعة اختبار', false]] as [$name, $skips]) {
            DB::table('product_categories')->insert([
                'name' => $name,
                'description' => null,
                'is_active' => true,
                'sort_order' => 0,
                'skips_production' => $skips,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Act
        Artisan::call('migrate');

        // Assert — the mapping the boolean always meant, row for row. A heading that skipped the
        // press is «سادة»; one that did not is «مطبوعة». Nothing is invented as «وسيط»: that is a
        // decision the business takes on a heading, and a migration guessing it would put orders
        // on a road nobody chose.
        $this->assertFalse(Schema::hasColumn('product_categories', 'skips_production'));

        $modes = DB::table('product_categories')
            ->whereIn('name', ['سادة اختبار', 'مطبوعة اختبار'])
            ->pluck('production_mode', 'name');

        $this->assertSame(ProductionMode::None->value, $modes['سادة اختبار']);
        $this->assertSame(ProductionMode::InHouse->value, $modes['مطبوعة اختبار']);
    }

    public function test_a_heading_with_no_answer_is_left_on_the_road_every_order_already_took(): void
    {
        // Arrange — the default, which is what every heading nobody has thought about carries.
        $this->rollBackToTheSchemaThatStillHadTheBoolean();

        DB::table('product_categories')->insert([
            'name' => 'تصنيف بلا قرار',
            'description' => null,
            'is_active' => true,
            'sort_order' => 0,
            // `skips_production` deliberately omitted: the column defaults to false, and this is
            // the row a category created before anybody cared about roads looks like.
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Act
        Artisan::call('migrate');

        // Assert — «مطبوعة», the road every order in the system walked before flows existed.
        $this->assertSame(
            ProductionMode::InHouse->value,
            DB::table('product_categories')->where('name', 'تصنيف بلا قرار')->value('production_mode'),
        );
    }
}
