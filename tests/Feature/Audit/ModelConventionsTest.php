<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Audit\Models\ActivityLog;
use App\Domain\Carrier\Models\NawrisWebhookEvent;
use App\Domain\Identity\Models\Role;
use App\Domain\Notification\Models\DeviceToken;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationRecipient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * The standing rule, enforced by the build rather than by remembering it.
 *
 * **Every model soft deletes, keeps an audit trail, and is registered in the morph map.** A rule
 * that lives only in RULES.md is a rule that holds until the first hurried afternoon. This test
 * walks `app/Domain/` and fails with the name of any model that skipped a step — which is how
 * the next person to add one finds out, before review rather than after release.
 *
 * It is the same shape as ErrorHandlingTest's no-try-catch check, and for the same reason.
 *
 * Arrange - Act - Assert throughout.
 */
class ModelConventionsTest extends TestCase
{
    /**
     * The audit trail's own model. Excluded from all three rules on purpose: a log entry that
     * logged itself would recurse, and one that could be deleted — softly or otherwise — would
     * not be an audit trail.
     *
     * @var list<class-string>
     */
    private const NOT_A_BUSINESS_RECORD = [
        ActivityLog::class,
        // The carrier's inbound webhook log. Same reasoning as the audit trail above: it
        // is already an immutable record of an external event, so auditing it would write
        // a second row saying the first arrived, and soft-deleting it would defeat the
        // point of keeping it. It is also the only table here that grows with traffic.
        NawrisWebhookEvent::class,

        // The notification centre's three tables, all event records rather than business
        // records — the same category as the two above.
        //
        // Auditing a notification would record that a record arrived, which is the recursion
        // ActivityLog is excluded to avoid. Soft-deleting one would defeat the retention prune
        // that is the only thing keeping these tables bounded: they grow with every status
        // change, every announcement and every employee, and nothing here is worth keeping
        // once it has been read and aged out.
        Notification::class,
        NotificationRecipient::class,

        // And the device tokens, which are not records at all but routing addresses. When FCM
        // answers UNREGISTERED the address is dead and the row must genuinely go — a soft
        // deleted token is one the push channel would keep finding and keep failing on.
        DeviceToken::class,
    ];

    public function test_every_domain_model_soft_deletes(): void
    {
        // Arrange
        $models = $this->domainModels();

        // Act
        $offenders = array_values(array_filter(
            $models,
            fn (string $model) => ! in_array(SoftDeletes::class, class_uses_recursive($model), true),
        ));

        // Assert
        $this->assertSame([], $offenders, $this->explain(
            'must `use SoftDeletes`. A deletion is the one change the audit trail cannot undo',
            $offenders,
        ));
    }

    public function test_every_domain_model_keeps_an_audit_trail(): void
    {
        // Arrange
        $models = $this->domainModels();

        // Act
        $offenders = array_values(array_filter(
            $models,
            fn (string $model) => ! in_array(Auditable::class, class_uses_recursive($model), true),
        ));

        // Assert
        $this->assertSame([], $offenders, $this->explain(
            'must `use Auditable`, so every change to it is recorded with who made it',
            $offenders,
        ));
    }

    public function test_every_domain_model_is_registered_in_the_morph_map(): void
    {
        // Arrange
        $models = $this->domainModels();
        $mapped = array_values(AuditSubject::morphMap());

        // Act
        $offenders = array_values(array_filter(
            $models,
            fn (string $model) => ! in_array($model, $mapped, true),
        ));

        // Assert
        $this->assertSame([], $offenders, $this->explain(
            'needs a case in AuditSubject, or its class name — not a stable alias — ends up in '.
            'activity_log.subject_type and in the API',
            $offenders,
        ));
    }

    public function test_every_morph_alias_names_a_model_that_exists(): void
    {
        // Arrange
        $map = AuditSubject::morphMap();

        // Act
        $missing = array_keys(array_filter($map, fn (string $class) => ! class_exists($class)));

        // Assert — the reverse direction: a case left behind after a model was renamed would
        // otherwise sit there resolving to nothing.
        $this->assertSame([], $missing);
    }

    public function test_every_soft_deleting_model_has_the_column_to_do_it_with(): void
    {
        // Arrange
        $models = array_merge($this->domainModels(), [Role::class]);

        // Act
        $offenders = [];

        foreach ($models as $model) {
            /** @var Model $instance */
            $instance = new $model;

            if (! Schema::hasColumn($instance->getTable(), 'deleted_at')) {
                $offenders[] = $model;
            }
        }

        // Assert — the trait without the column is a 500 on the first delete, and nothing
        // before that point says so.
        $this->assertSame([], $offenders, $this->explain('has no `deleted_at` column', $offenders));
    }

    public function test_the_audit_log_itself_is_neither_audited_nor_soft_deleted(): void
    {
        // Arrange
        $traits = class_uses_recursive(ActivityLog::class);

        // Act & Assert — stated as a test so it reads as a decision rather than an omission.
        $this->assertNotContains(Auditable::class, $traits);
        $this->assertNotContains(SoftDeletes::class, $traits);
    }

    /**
     * Every Eloquent model under `app/Domain/*​/Models/`, found on disk rather than listed here
     * — a list would need updating by the very person this test exists to catch.
     *
     * @return list<class-string<Model>>
     */
    private function domainModels(): array
    {
        $root = app_path('Domain');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $models = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname());

            if (! str_contains($relative, DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $class = 'App\\Domain\\'.str_replace(
                [DIRECTORY_SEPARATOR, '.php'],
                ['\\', ''],
                $relative,
            );

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (in_array($class, self::NOT_A_BUSINESS_RECORD, true)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        // A guard on the guard: if the scan ever stops finding files, every assertion above
        // would pass against an empty list and say nothing at all.
        $this->assertNotEmpty($models, 'No domain models were found — the scan itself is broken.');

        return $models;
    }

    /**
     * @param  list<string>  $offenders
     */
    private function explain(string $requirement, array $offenders): string
    {
        return 'Every model in app/Domain/ '.$requirement.'. Offending models: '
            .($offenders === [] ? 'none' : implode(', ', $offenders));
    }
}
