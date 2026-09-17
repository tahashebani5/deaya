<?php

use App\Domain\Audit\Enums\AuditSubject;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rewrites every stored polymorphic type from a PHP class name to its morph alias.
 *
 * The audit trail introduces {@see AuditSubject}, a morph map that makes `subject_type` read
 * `user` rather than `App\Domain\Identity\Models\User`. A morph map is application-wide: the
 * moment it is registered, Eloquent *writes* aliases and *matches* on aliases everywhere. Rows
 * written before it would keep their class names and simply stop being found — a user's roles
 * would silently vanish, and every issued API token would stop resolving to its owner.
 *
 * So the map and this backfill are one change, and this is the migration that makes the
 * existing rows agree with it.
 *
 * This is deliberate re-encoding of a value, not the data cleanup §8 of RULES.md forbids: there
 * is exactly one correct new value for each old one, and no business decision to make.
 */
return new class extends Migration
{
    /**
     * Every column in the schema that stores a polymorphic type, as table => column.
     *
     * `activity_log` is absent on purpose — it is created empty by the migration before this
     * one, so there is nothing in it to rewrite.
     *
     * @var array<string, string>
     */
    private const MORPH_COLUMNS = [
        'model_has_roles' => 'model_type',
        'model_has_permissions' => 'model_type',
        'personal_access_tokens' => 'tokenable_type',
    ];

    public function up(): void
    {
        $this->rewrite(fn (string $alias, string $class) => [$class, $alias]);
    }

    public function down(): void
    {
        $this->rewrite(fn (string $alias, string $class) => [$alias, $class]);
    }

    /**
     * @param  Closure(string, class-string): array{0: string, 1: string}  $direction
     */
    private function rewrite(Closure $direction): void
    {
        foreach (self::MORPH_COLUMNS as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (AuditSubject::morphMap() as $alias => $class) {
                [$from, $to] = $direction($alias, $class);

                DB::table($table)->where($column, $from)->update([$column => $to]);
            }
        }
    }
};
