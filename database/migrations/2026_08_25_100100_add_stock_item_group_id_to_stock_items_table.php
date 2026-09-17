<?php

use App\Domain\Inventory\Actions\UpdateStockItemGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Files every shelf under the material it is a size of.
 *
 * **Nullable on purpose.** A standalone item — «حبر أسود», a roll nothing else is a size of —
 * is a real thing, and forcing a group on it would mean inventing a group of one to get past the
 * form. Grouping is what makes a *family of sizes* linkable in one move; it is not a rule about
 * what may exist.
 *
 * `nullOnDelete`, not `cascadeOnDelete`: removing a group must never take the shelves with it —
 * they hold stock. `DeleteStockItemGroup` refuses while any item still points here, so in
 * practice this only ever nulls out rows that were already detached.
 *
 * **Backfilled, not left empty.** One group per distinct `stock_items.name`, taking that name's
 * first `unit` as its default, and every item repointed at it. That is what keeps
 * `stock_items_name_size_unique` meaningful once groups exist: a grouped item carries its group's
 * name, group names are unique, so `(name, size)` still identifies exactly one shelf and the
 * resolver can never be handed two candidates. Renaming a group afterwards renames its items in
 * the same transaction — see {@see UpdateStockItemGroup}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->foreignId('stock_item_group_id')->nullable()->after('code')
                ->constrained('stock_item_groups')->nullOnDelete();
        });

        // One group per material name. `nextval` is taken per row so the code and the id agree —
        // a group whose id is 7 always reads G7, the same guarantee AllocateStockItemGroupIdentifier
        // gives at runtime. `DISTINCT ON (name) … ORDER BY name, id` picks the oldest item's unit
        // as the group's default, which is the one most of its siblings will share.
        DB::statement(
            "WITH materials AS (
                 SELECT DISTINCT ON (name) name, unit
                 FROM stock_items
                 WHERE deleted_at IS NULL
                 ORDER BY name, id
             ),
             numbered AS (
                 SELECT name, unit,
                        nextval(pg_get_serial_sequence('stock_item_groups', 'id')) AS id
                 FROM materials
             )
             INSERT INTO stock_item_groups
                 (id, code, name, default_unit, is_active, sort_order, created_at, updated_at)
             SELECT id, 'G' || id, name, unit, true, 0, now(), now()
             FROM numbered"
        );

        // Deleted items are repointed too: they can be restored, and one coming back with no
        // group would be the single row the resolver cannot explain.
        DB::statement(
            'UPDATE stock_items
             SET stock_item_group_id = g.id
             FROM stock_item_groups g
             WHERE g.name = stock_items.name'
        );
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_item_group_id');
        });

        DB::statement('DELETE FROM stock_item_groups');
    }
};
