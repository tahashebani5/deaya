<?php

use App\Domain\Inventory\Enums\WarehouseType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every warehouse still labelled «صالة العرض» becomes a main store.
 *
 * Dropping the Showroom case from {@see WarehouseType} left the rows that carried it behind, and
 * the cast on the model turns each one into a 500 the moment the list is opened — the type is a
 * label, but an unreadable label is still an unreadable row.
 *
 * `main` rather than `operational` because the two surviving cases describe *where stock sits*,
 * and a display floor is emphatically not the workshop floor pulling material forward for the
 * machines. Nothing branches on the type, so this changes what the row is called and nothing
 * about what it does.
 *
 * The warehouses themselves are left standing. They are real places holding real shelves, and a
 * type the business stopped using is not a reason to delete one — a storekeeper who wants it gone
 * can delete it in the app, where the "still holds stock" rule can refuse.
 *
 * Its own migration, per RULES §8: a schema migration does not carry data changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('warehouses')
            ->where('type', 'showroom')
            ->update(['type' => WarehouseType::Main->value]);
    }

    public function down(): void
    {
        // Nothing to undo that would not be a guess: which of these rows this migration relabelled,
        // and which were already main stores, is not recorded anywhere.
    }
};
