<?php

use App\Domain\Inventory\Actions\AllocateStockItemGroupIdentifier;
use App\Domain\Inventory\Actions\ResolveStockItemForVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «التصنيف» — the family a material is filed under: «كيس شحن», «كيس ورقي».
 *
 * **A group holds nothing.** It has no balance, no cost layer and no size; it is the name every
 * one of its sizes shares. `stock_items` remains the only thing a warehouse can put a quantity
 * on, which is why this is a table of its own rather than a `parent_id` on that one — a
 * self-referencing table would make a parent and a child two different kinds of row wearing the
 * same shape, and every picker in Inventory would need to remember to filter one of them out.
 *
 * **What it is for.** A product points at one group and stops naming shelves size by size:
 * {@see ResolveStockItemForVariant} matches each variant to the group's item of the same size,
 * creating it if the group has not reached that size yet. كيس شحن سادة and كيس شحن مطبوع both
 * point at «كيس شحن», and both land on the same pile at every size they share — which is the
 * sharing `stock_items` was built for, without anyone picking it by hand.
 *
 * `default_unit` is what an auto-created size is counted in. Deliberately *not* the authority for
 * an existing item's unit: that stays on `stock_items.unit`, moved only by `SetStockItemUnit`
 * under locks, because every balance and batch carries a snapshot of it. A group is where the
 * default comes from, not where the truth lives.
 *
 * `code` is `G` + the row id, reserved before insert by {@see AllocateStockItemGroupIdentifier} —
 * the same move products, customers and stock items already make.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_item_groups', function (Blueprint $table) {
            $table->id();

            $table->string('code', 20);

            // The material, without a size: «كيس شحن». Every item under it carries this same
            // name, so renaming here renames them all — see UpdateStockItemGroup.
            $table->string('name');

            // What a size created under this group starts out counted in.
            $table->string('default_unit', 20);   // PricingUnit

            $table->text('description')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes()->index();
        });

        // Partial, like every unique index in this schema: deleting a group releases its code.
        DB::statement(
            'CREATE UNIQUE INDEX stock_item_groups_code_unique
             ON stock_item_groups (code) WHERE deleted_at IS NULL'
        );

        // One group per material name — and this is load-bearing, not tidiness. `stock_items`
        // is unique on (name, size), and every grouped item takes its group's name, so a
        // duplicate group name would let two groups fight over the same (name, size) row and
        // the resolver would have no way to say which shelf a variant meant.
        DB::statement(
            'CREATE UNIQUE INDEX stock_item_groups_name_unique
             ON stock_item_groups (name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_item_groups');
    }
};
