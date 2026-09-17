<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\AuditSubject;
use App\Domain\Identity\Enums\PermissionName;
use App\Domain\Order\Enums\OrderStatus;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * What each value in a history entry *says*, in Arabic.
 *
 * {@see AuditAttributeLabels} named the columns and stopped there, so the screen read «الحالة:
 * new ← printing» — an Arabic label followed by an English value, while the same status reads
 * «جديدة ← قيد الطباعة» on the order card three taps away. This file closes that half.
 *
 * **The rule is that nothing here is a dictionary of values.** A value is translated by the
 * thing that already knows its type — the model's own `casts()`. `Order` declares `status` as
 * {@see OrderStatus}; that enum already says «قيد الطباعة» because the
 * order card needed it; so the history says it too, and nobody had to write it twice. The same
 * goes the other way: an enum added tomorrow is translated the morning it is cast, not the
 * morning somebody remembers this file. A hand-kept list would have been wrong in between, with
 * no build failing to say so — which is the argument {@see AuditAttributeLabels} makes for the
 * column names, applied to what fills them.
 *
 * Foreign keys are the one case with nothing to derive from a cast, so they are derived from the
 * model's `belongsTo` instead — `customer_id` is read through `customer()`. That costs a query,
 * which is why {@see AuditReferenceNames} does it for a whole page at once.
 *
 * Everything else is left alone on purpose. A name is already Arabic, a total is a number, and
 * «نعم/لا» the app says for itself — translating those would ship every row twice for nothing.
 * A value with no translation is simply absent from the output, and the client falls back to the
 * raw value: the same bargain, and the same failure mode, as the column labels.
 */
final class AuditValueLabels
{
    /**
     * Both halves of a change, translated.
     *
     * Mirrors the `changes` object key for key rather than being a value => label dictionary,
     * because a dictionary cannot hold this data: JSON keys are strings, so `12` and `"12"`
     * collide; `12` in `customer_id` and `12` in `city_id` are two different records in one row;
     * and `null` cannot be a key at all. Column plus half is the only key that never lies.
     *
     * Both halves are taken as `mixed` rather than `array` on purpose: they come straight out of
     * a JSON column, and a row written by an older build — or by hand — can hold anything at
     * all. A history screen is the last place that should 500 over its own oldest rows.
     *
     * @return array{old: array<string, string>, attributes: array<string, string>}
     */
    public static function forChanges(
        ?AuditSubject $subject,
        mixed $old,
        mixed $new,
        ?AuditReferenceNames $names = null,
    ): array {
        if ($subject === null) {
            return ['old' => [], 'attributes' => []];
        }

        $model = new ($subject->modelClass());
        $names ??= AuditReferenceNames::empty();

        return [
            'old' => self::translate($model, is_array($old) ? $old : [], $names),
            'attributes' => self::translate($model, is_array($new) ? $new : [], $names),
        ];
    }

    /**
     * Which of these columns point at another record, and at what.
     *
     * Read by the collection to work out what one page needs to look up, before any of it is
     * rendered. Derived from the model's relations, so a foreign key added tomorrow is resolved
     * without being listed anywhere.
     *
     * @param  list<string>  $attributes
     * @return array<string, class-string<Model>> column => model it names
     */
    public static function referencesFor(?AuditSubject $subject, array $attributes): array
    {
        if ($subject === null || $attributes === []) {
            return [];
        }

        $model = new ($subject->modelClass());
        $references = [];

        foreach ($attributes as $column) {
            $related = self::relatedModel($model, $column);

            if ($related !== null) {
                $references[$column] = $related;
            }
        }

        return $references;
    }

    /**
     * The one thing recorded by hand rather than by a model event: the permissions a role gained
     * or lost.
     *
     * Here a value => label dictionary *is* the right shape, and for the reason it is the wrong
     * one above: a permission's value is a unique string that means the same thing wherever it
     * appears, so `products.view` can key its own translation without ambiguity.
     *
     * @param  array<string, mixed>|Collection<string, mixed>|null  $properties
     * @return array<string, array<string, string>>
     */
    public static function forProperties(array|Collection|null $properties): array
    {
        $properties = $properties instanceof Collection ? $properties->all() : ($properties ?? []);
        $permissions = $properties['permissions'] ?? null;

        if (! is_array($permissions)) {
            return [];
        }

        $labels = [];

        // Every half at once — `old`, `attributes`, `granted`, `revoked` — because all four are
        // drawn, and a permission named in one is named in the others.
        foreach ($permissions as $half) {
            foreach (is_array($half) ? $half : [] as $value) {
                $case = is_string($value) ? PermissionName::tryFrom($value) : null;

                if ($case !== null) {
                    $labels[$value] = $case->label();
                }
            }
        }

        return $labels === [] ? [] : ['permissions' => $labels];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private static function translate(Model $model, array $values, AuditReferenceNames $names): array
    {
        $labels = [];

        foreach ($values as $column => $value) {
            $label = self::valueLabel($model, (string) $column, $value, $names);

            if ($label !== null) {
                $labels[(string) $column] = $label;
            }
        }

        return $labels;
    }

    /**
     * Null means "no translation" — never an empty string, which would draw a blank where the
     * raw value should have been.
     */
    private static function valueLabel(
        Model $model,
        string $column,
        mixed $value,
        AuditReferenceNames $names,
    ): ?string {
        // «—» for null and «فارغ» for a cleared field are the app's job and it already does
        // them. An array is a `casts()` payload — features, properties — and has no one label.
        if ($value === null || $value === '' || is_array($value) || is_bool($value)) {
            return null;
        }

        $enum = self::enumCast($model, $column);

        if ($enum !== null) {
            return self::enumLabel($enum, $value);
        }

        $related = self::relatedModel($model, $column);

        if ($related !== null && (is_int($value) || is_string($value))) {
            return $names->nameFor($related, $value);
        }

        return null;
    }

    /**
     * The enum a column is stored as, if it is stored as one and can name itself.
     *
     * @return class-string<BackedEnum>|null
     */
    private static function enumCast(Model $model, string $column): ?string
    {
        $cast = $model->getCasts()[$column] ?? null;

        if (! is_string($cast) || ! enum_exists($cast)) {
            return null;
        }

        if (! is_subclass_of($cast, BackedEnum::class) || ! method_exists($cast, 'label')) {
            return null;
        }

        /** @var class-string<BackedEnum> $cast */
        return $cast;
    }

    /**
     * Matched by string rather than through `tryFrom`, which would fatal on an int-backed enum
     * handed the string a JSON payload gives back. A case this build no longer has returns null
     * and the raw value survives — history written by an older build has to stay readable.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    private static function enumLabel(string $enum, mixed $value): ?string
    {
        foreach ($enum::cases() as $case) {
            if ((string) $case->value === (string) $value) {
                /** @phpstan-ignore-next-line method.notFound — guarded by enumCast() */
                return $case->label();
            }
        }

        return null;
    }

    /**
     * The model a `*_id` column names, read from the relation the model already declares.
     *
     * `customer_id` → `customer()`, which is Eloquent's own convention and therefore true of
     * every relation in this application without anything being registered. A column with no
     * matching relation — `product_variant_id` on a model that never declared one — is simply
     * not a reference, and its id survives to the screen.
     *
     * `created_by` and `reviewed_by` hold user ids and are deliberately **not** covered: the
     * relation name cannot be derived from the column, and guessing `creator()` would be a rule
     * that works until it doesn't. They need {@see HasAuditDisplayName}'s equivalent for
     * columns, or an explicit entry, and are listed in the design note rather than half-done.
     *
     * @return class-string<Model>|null
     */
    private static function relatedModel(Model $model, string $column): ?string
    {
        if (! str_ends_with($column, '_id')) {
            return null;
        }

        $method = Str::camel(substr($column, 0, -3));

        if (! method_exists($model, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($model, $method);

        // A relation takes no arguments and says so in its return type. Calling something that
        // merely shares the name — `order()`, a scope, an accessor — is how a convenience turns
        // into a 500 on a history screen.
        if (! $reflection->isPublic() || $reflection->getNumberOfParameters() > 0) {
            return null;
        }

        $returns = $reflection->getReturnType();

        if (! $returns instanceof ReflectionNamedType || ! is_a($returns->getName(), BelongsTo::class, true)) {
            return null;
        }

        /** @var BelongsTo<Model, Model> $relation */
        $relation = $model->{$method}();

        if ($relation->getForeignKeyName() !== $column) {
            return null;
        }

        return $relation->getRelated()::class;
    }
}
