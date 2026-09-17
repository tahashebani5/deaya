<?php

use App\Domain\Identity\Actions\AllocateEmployeeCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The short number every employee is known by on the shop floor — what the home screen shows
 * under their name, and what a colleague quotes when asking who handled an order.
 *
 * Drawn from a sequence of its own, {@see AllocateEmployeeCode}, so two accounts created at the
 * same instant cannot land on one code. It starts at 1001 so a code is never mistaken for a row
 * number or a quantity.
 *
 * **Nullable, and staying that way for now.** Every row is filled below and the model assigns
 * one to every account it creates, so in practice the column is never null — but tightening it
 * to NOT NULL belongs in a follow-up, once this has run everywhere. The backfill here is not
 * data cleanup: no existing value is being corrected, the rows simply predate the column and a
 * correct value *can* be derived for each of them.
 *
 * The unique index is partial, matching every other unique index in this schema: a deleted
 * employee releases their code from the index while keeping it on their own row, so the
 * history that names them still reads correctly.
 */
return new class extends Migration
{
    private const INDEX = 'users_employee_code_unique';

    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS '.AllocateEmployeeCode::SEQUENCE.' START WITH 1001');

        Schema::table('users', function (Blueprint $table): void {
            $table->string('employee_code', 12)->nullable()->after('phone');
        });

        // Oldest account first, so the codes follow the order people joined in.
        foreach (DB::table('users')->orderBy('id')->pluck('id') as $id) {
            DB::table('users')
                ->where('id', $id)
                ->update(['employee_code' => (string) DB::scalar('select nextval(?)', [AllocateEmployeeCode::SEQUENCE])]);
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX.' ON users (employee_code) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('employee_code');
        });

        DB::statement('DROP SEQUENCE IF EXISTS '.AllocateEmployeeCode::SEQUENCE);
    }
};
