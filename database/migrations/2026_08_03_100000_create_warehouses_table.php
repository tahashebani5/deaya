<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A place stock is held — the main store or the workshop floor.
 *
 * `type` is what tells the business which of those a row is, and it is deliberately *not* a
 * behaviour switch: nothing in the code branches on it today. It labels and groups a warehouse
 * in a picker. The moment a rule genuinely differs by type — say the workshop floor cannot
 * receive an arrival — it belongs in WarehouseType as a predicate, next to the cases, rather than as a
 * string compared in three different actions.
 *
 * A warehouse *is* deletable, which makes it the second table after cities to carry a real
 * destroy route. Deleting one is refused while it still holds stock: stock inside a warehouse
 * the API says does not exist is a balance nobody will ever reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);

            $table->string('type', 20)->index();   // WarehouseType

            // Where it physically is, written the way staff say it — "الطريق الساحلي، جنزور".
            // Nullable for the same reason cities.darb_branch is: a site that exists before
            // anyone has written its address down is a real state, and blocking the row on it
            // would only teach people to type a placeholder.
            $table->string('location')->nullable();

            $table->timestamps();
            $table->softDeletes()->index();
        });

        // Two warehouses sharing a name is a picker that cannot be used — the storekeeper
        // choosing a destination has no way to tell them apart. Partial, like every unique
        // index in this schema: deleting a warehouse releases its name.
        DB::statement(
            'CREATE UNIQUE INDEX warehouses_name_unique
             ON warehouses (name) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
