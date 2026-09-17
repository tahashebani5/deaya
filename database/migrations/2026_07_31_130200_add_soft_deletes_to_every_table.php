<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every business table a `deleted_at`.
 *
 * A deletion is the one change an audit trail cannot help you undo: the row it describes is
 * gone, so "who deleted this and what was in it" answers the question without letting anyone
 * act on the answer. Soft deleting keeps the row, which makes the trail's `restored` event a
 * real operation rather than a category the code can never produce.
 *
 * It also fixes a subtler problem. `activity_log.subject_id` is a plain integer with no foreign
 * key — it cannot have one, because it points at nine different tables. Hard-deleting left
 * every historical row about that record pointing at nothing.
 *
 * **The pivot tables are left alone.** `model_has_roles`, `role_has_permissions` and the rest
 * hold no history of their own: a role assignment is either current or it is not, and the fact
 * that it changed is recorded against the user, by the audit trail.
 */
return new class extends Migration
{
    /**
     * Every table that holds a business record.
     *
     * @var list<string>
     */
    private const TABLES = [
        'users',
        'customers',
        'customer_shops',
        'products',
        'product_variants',
        'product_price_tiers',
        'product_images',
        'cities',
        'regions',
        'roles',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                // Indexed: every read on these tables now carries `where deleted_at is null`,
                // so the column is in the hottest predicate the application has.
                $blueprint->softDeletes()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
