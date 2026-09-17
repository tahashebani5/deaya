<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Api\V1\Middleware\DeshapeArabicInput;
use App\Domain\Customer\Models\Customer;
use App\Support\ArabicText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `text:deshape` — the one-off that cleans what was stored before the door was closed.
 *
 * **Written for the rows that predate {@see DeshapeArabicInput}.**
 * Everything typed from now on is folded on the way in; everything typed before it is still
 * sitting in the table exactly as the keyboard sent it, and one such character in a customer's
 * name is what stopped order 1228's invoice from being drawn. See {@see ArabicText}.
 *
 * Arrange - Act - Assert throughout.
 */
class DeshapeStoredTextTest extends TestCase
{
    use RefreshDatabase;

    /**
     * «شركة بريمولا», keyed already-shaped.
     */
    private const SHAPED = "\u{FEB7}\u{FEAE}\u{FEDC}\u{FE94} \u{FE91}\u{FEAE}\u{FEF3}\u{FEE4}\u{FEEE}\u{FEDF}\u{FE8E}";

    public function test_it_folds_a_name_that_was_stored_before_the_door_was_closed(): void
    {
        // Arrange — written straight to the table, which is how these rows got there: through a
        // build of the API that had no middleware to fold them.
        $customer = Customer::factory()->create();
        DB::table('customers')->where('id', $customer->id)->update(['name' => self::SHAPED]);

        // Act
        $this->artisan('text:deshape', ['--force' => true])->assertSuccessful();

        // Assert
        $this->assertSame('شركة بريمولا', (string) DB::table('customers')->where('id', $customer->id)->value('name'));
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        // Arrange — the first thing anyone should run against a live database.
        $customer = Customer::factory()->create();
        DB::table('customers')->where('id', $customer->id)->update(['name' => self::SHAPED]);

        // Act
        $this->artisan('text:deshape', ['--force' => true, '--dry-run' => true])->assertSuccessful();

        // Assert
        $this->assertSame(self::SHAPED, (string) DB::table('customers')->where('id', $customer->id)->value('name'));
    }

    public function test_rows_that_were_always_letters_are_not_touched(): void
    {
        // Arrange — nothing to fold means nothing written, so `updated_at` still says when a
        // person last changed this record rather than when a cleanup ran over it.
        $customer = Customer::factory()->create(['name' => 'مخبز النخيل']);
        $before = (string) DB::table('customers')->where('id', $customer->id)->value('updated_at');

        // Act
        $this->artisan('text:deshape', ['--force' => true])->assertSuccessful();

        // Assert
        $row = DB::table('customers')->where('id', $customer->id)->first();

        $this->assertSame('مخبز النخيل', (string) $row->name);
        $this->assertSame($before, (string) $row->updated_at);
    }

    public function test_the_audit_trail_is_left_exactly_as_it_was_written(): void
    {
        // Arrange — history is a record of what happened, including what was typed. Rewriting it
        // to read better would make it a worse record, and nothing ever draws it into a PDF.
        DB::table('activity_log')->insert([
            'log_name' => 'default',
            'description' => self::SHAPED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Act
        $this->artisan('text:deshape', ['--force' => true])->assertSuccessful();

        // Assert
        $this->assertSame(self::SHAPED, (string) DB::table('activity_log')->latest('id')->value('description'));
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        // Arrange
        $customer = Customer::factory()->create();
        DB::table('customers')->where('id', $customer->id)->update(['name' => self::SHAPED]);

        $this->artisan('text:deshape', ['--force' => true])->assertSuccessful();
        $after = (string) DB::table('customers')->where('id', $customer->id)->value('updated_at');

        // Act
        $this->artisan('text:deshape', ['--force' => true])->assertSuccessful();

        // Assert
        $row = DB::table('customers')->where('id', $customer->id)->first();

        $this->assertSame('شركة بريمولا', (string) $row->name);
        $this->assertSame($after, (string) $row->updated_at);
    }
}
