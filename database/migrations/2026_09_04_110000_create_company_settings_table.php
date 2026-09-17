<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row, one column per company-wide default the business edits from a screen.
 *
 * **Not `config/`.** Every company value in this project today is `env()`-backed and read
 * through a cached config, and `bin/rebuild-caches` exists because of the day a cached config
 * predated a key and every upload was refused with «لا يمكن تجاوز 0 صور». A number the owner
 * changes from his phone must never need `config:cache` afterwards.
 *
 * **A default, not a rate that reaches back.** The precedent is `manufacturing_cost_rates`,
 * whose own migration says it plainly: changing a rate affects only what is costed after the
 * change, and history stays true because the applied value is snapshotted where it was used.
 * The same split here — this seeds `investor_deals.investor_profit_share_percent` when a deal
 * is created, and is never read again for that deal. Editing it tomorrow moves no closed deal's
 * numbers, which is the whole reason it is safe to have.
 *
 * A typed column per setting rather than a key/value pair: two settings do not justify an
 * untyped `value` column and a cast table to go with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();

            // What share of a deal's profit goes to its investors, used as the default on a new
            // deal. 50 by default because that is the arrangement the business already has.
            $table->decimal('investor_profit_share_percent', 5, 2)->default('50.00');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes()->index();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE company_settings
            ADD CONSTRAINT company_settings_investor_share_range
            CHECK (investor_profit_share_percent >= 0 AND investor_profit_share_percent <= 100)
        SQL);

        // «The setting», singular, is a claim the database should be making rather than a
        // convention the reader hopes holds. Without it a second row is insertable and nothing
        // says which one a deal reads — and the reader would then have to guess a default it
        // exists precisely in order not to hardcode.
        DB::statement(<<<'SQL'
            ALTER TABLE company_settings
            ADD CONSTRAINT company_settings_singleton CHECK (id = 1)
        SQL);

        // The singleton, written here rather than in a seeder: a deploy that ran migrations and
        // not seeders would otherwise leave every deal creation reading a row that is not there.
        DB::table('company_settings')->insert([
            'id' => 1,
            'investor_profit_share_percent' => '50.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
