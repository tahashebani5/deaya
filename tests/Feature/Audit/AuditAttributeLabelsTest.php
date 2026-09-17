<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditAttributeLabels;
use App\Domain\Audit\Enums\AuditSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The guard {@see AuditAttributeLabels} has always claimed to have.
 *
 * Its own docblock says the test «reads the real tables and fails before it can ship» — and the
 * test did not exist. A dictionary keyed by column name rots in two directions and neither one
 * announces itself: a column renamed in a migration leaves a label pointing at nothing, and a
 * column added leaves a history screen printing `min_order_quantity` at somebody. Both look
 * fine in review.
 *
 * So the check is against the schema itself, not against a list kept here — a list would need
 * updating by the very person this exists to catch.
 *
 * Arrange - Act - Assert throughout.
 */
class AuditAttributeLabelsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Never logged, so never in need of a name: the id never changes, and the timestamps are
     * already on the log row itself. Kept in step with `Auditable::attributesNeverWorthLogging()`.
     *
     * @var list<string>
     */
    private const NEVER_LOGGED = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * The columns the trail refuses outright — `password`, `remember_token` — read from the
     * config that strips them rather than restated here. A secret that never reaches the log
     * needs no label, and listing it in a second place is how the two drift apart.
     *
     * @return list<string>
     */
    private function neverLogged(): array
    {
        return array_merge(
            self::NEVER_LOGGED,
            array_values((array) config('activitylog.default_except_attributes', [])),
        );
    }

    public function test_every_label_names_a_column_that_still_exists(): void
    {
        // Arrange — the direction that rots silently: a migration renames `category`, and the
        // label stays behind pointing at a column no row will ever carry again.
        $offenders = [];

        // Act
        foreach (AuditSubject::cases() as $subject) {
            /** @var Model $model */
            $model = new ($subject->modelClass());
            $columns = Schema::getColumnListing($model->getTable());

            foreach (array_keys(AuditAttributeLabels::for($subject)) as $column) {
                // The shared dictionary is deliberately wider than any one table — `phone` and
                // `notes` are on several models and on none of the others — so only a label the
                // subject's *own* dictionary names is a claim about this table.
                if (! in_array($column, $columns, true) && $this->isOwnedBy($subject, $column)) {
                    $offenders[] = $subject->value.'.'.$column;
                }
            }
        }

        // Assert
        $this->assertSame([], $offenders, implode("\n", [
            'These labels name columns that no longer exist. The history screen will never draw',
            'them, and the real column is going out unlabelled instead:',
            ...$offenders,
        ]));
    }

    public function test_every_column_a_record_can_log_has_an_arabic_name(): void
    {
        // Arrange — the other direction, and the one the screen shows: a column added to a
        // migration and not to the dictionary reaches the user as a database key.
        $offenders = [];

        // Act
        foreach (AuditSubject::cases() as $subject) {
            /** @var Model $model */
            $model = new ($subject->modelClass());
            $labels = AuditAttributeLabels::for($subject);

            foreach (Schema::getColumnListing($model->getTable()) as $column) {
                if (in_array($column, $this->neverLogged(), true)) {
                    continue;
                }

                if (! array_key_exists($column, $labels)) {
                    $offenders[] = $subject->value.'.'.$column;
                }
            }
        }

        // Assert
        $this->assertSame([], $offenders, implode("\n", [
            'Every column a model can log reaches a history screen, so every one needs an Arabic',
            'name. Unlabelled, it is drawn as the raw column — which is what this file exists to',
            'end. Add each of these to AuditAttributeLabels:',
            ...$offenders,
        ]));
    }

    /**
     * Whether this label comes from the subject's own dictionary rather than the shared one.
     *
     * Only the former is a statement about this table. `phone` is shared by customers, shops and
     * vendors, and its absence from a table that has no phone is not an error.
     */
    private function isOwnedBy(AuditSubject $subject, string $column): bool
    {
        $shared = AuditAttributeLabels::for($subject);
        $ownLabel = $shared[$column] ?? null;

        // A column the shared list also names is only "owned" when the subject overrides it.
        return $ownLabel !== null && ! $this->isSharedColumn($column, $ownLabel);
    }

    private function isSharedColumn(string $column, string $label): bool
    {
        foreach (AuditSubject::cases() as $other) {
            if ((AuditAttributeLabels::for($other)[$column] ?? null) !== $label) {
                return false;
            }
        }

        return true;
    }
}
